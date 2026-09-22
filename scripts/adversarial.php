<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Validator;
use Orchestra\Testbench\Foundation\Application;
use Psr\Log\NullLogger;
use Vlados\LaravelSpamGuard\Rules\NotSpam;
use Vlados\LaravelSpamGuard\SpamGuard;
use Vlados\LaravelSpamGuard\SpamGuardServiceProvider;
use Vlados\LaravelSpamGuard\SpamQuestion;
use Vlados\LaravelSpamGuard\TypeSafeClient;
use Vlados\LaravelSpamGuard\Verdict;

require dirname(__DIR__).'/vendor/autoload.php';

final class RecordingSpamGuard extends SpamGuard
{
    public ?Verdict $lastVerdict = null;

    public function check(mixed $state, ?string $context = null): Verdict
    {
        return $this->lastVerdict = parent::check($state, $context);
    }
}

try {
    $options = getopt('', ['live', 'only:', 'repeat:', 'timeout:', 'context:', 'label:', 'corpus:', 'question:', 'offset:', 'limit:', 'threshold:']);
    $root = dirname(__DIR__);
    $corpus = $options['corpus'] ?? 'original';
    $corpusFile = match ($corpus) {
        'original' => 'adversarial.json',
        'development' => 'adversarial-development.json',
        'holdout' => 'adversarial-holdout.json',
        'mutations' => 'adversarial-mutations.json',
        'stress' => 'adversarial-stress.json',
        default => throw new InvalidArgumentException('Unknown corpus.'),
    };
    $cases = json_decode(file_get_contents($root.'/tests/Fixtures/'.$corpusFile), true, flags: JSON_THROW_ON_ERROR);
    if (isset($options['only'])) {
        $ids = explode(',', $options['only']);
        $cases = array_values(array_filter($cases, fn ($case) => in_array($case['id'], $ids, true)));
    }
    $cases = array_slice($cases, max(0, (int) ($options['offset'] ?? 0)), max(1, (int) ($options['limit'] ?? 100)));
    $repeat = (int) ($options['repeat'] ?? 1);
    if ($cases === [] || $repeat < 1 || $repeat > 3 || count($cases) * $repeat > 100) {
        throw new InvalidArgumentException('Invalid batch selection or batch exceeds 100 calls.');
    }
    if (! array_key_exists('live', $options)) {
        echo count($cases) * $repeat." synthetic checks selected. Add --live to contact TypeSafe.\n";
        exit(0);
    }

    $env = Dotenv::parse(file_get_contents($root.'/.env'));
    $key = $env['TYPESAFE_API_KEY'] ?? '';
    unset($env);
    if (trim($key) === '') {
        throw new InvalidArgumentException('Missing API key.');
    }

    $app = Application::create(options: ['extra' => ['providers' => [SpamGuardServiceProvider::class]]]);
    $config = $app->make(Repository::class);
    $config->set('spam-guard.api_key', $key);
    unset($key);
    if (isset($options['context'])) {
        $config->set('spam-guard.context', $options['context']);
    }
    if (isset($options['threshold'])) {
        $config->set('spam-guard.threshold', (float) $options['threshold']);
    }
    if (isset($options['timeout'])) {
        $timeout = (float) $options['timeout'];
        if ($timeout < 1 || $timeout > 10) {
            throw new InvalidArgumentException('Diagnostic timeout must be between 1 and 10 seconds.');
        }
        $config->set('spam-guard.timeout', $timeout);
    }

    $questionName = $options['question'] ?? 'production';
    $question = match ($questionName) {
        'production' => SpamQuestion::forContext($config->get('spam-guard.context')),
        'baseline' => json_decode(file_get_contents($root.'/tests/Fixtures/questions/baseline.json'), true, flags: JSON_THROW_ON_ERROR),
        'mixed-content' => json_decode(file_get_contents($root.'/tests/Fixtures/questions/mixed-content.json'), true, flags: JSON_THROW_ON_ERROR),
        default => throw new InvalidArgumentException('Unknown question.'),
    };
    $question['instructions']['form_purpose'] = $config->get('spam-guard.context');
    $http = $app->make(Factory::class);
    $http->globalRequestMiddleware(function ($request) use ($question) {
        $body = json_decode((string) $request->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $body['questions']['spam'] = $question;
        $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return $request->withBody(Utils::streamFor($json))->withHeader('Content-Length', (string) strlen($json));
    });
    $client = new TypeSafeClient($http, $config, new NullLogger);
    $guard = new RecordingSpamGuard($config, $client);
    $app->instance(SpamGuard::class, $guard);
    $label = preg_replace('/[^a-zA-Z0-9_-]/', '-', (string) ($options['label'] ?? 'baseline'));
    $path = $root.'/docs/adversarial/'.gmdate('Y-m-d-His').'-'.$label.'.json';
    $report = [
        'started_at' => gmdate(DATE_ATOM),
        'synthetic_only' => true,
        'configuration' => array_diff_key($config->get('spam-guard'), ['api_key' => true]),
        'question_sha256' => hash_file('sha256', $root.'/src/SpamQuestion.php'),
        'evaluation_question' => $question,
        'evaluation_question_name' => $questionName,
        'evaluation_question_sha256' => hash('sha256', json_encode($question, JSON_THROW_ON_ERROR)),
        'corpus' => $corpus,
        'corpus_sha256' => hash_file('sha256', $root.'/tests/Fixtures/'.$corpusFile),
        'planned_checks' => count($cases) * $repeat,
        'results' => [],
    ];
    $consecutiveUnavailable = 0;
    foreach (range(1, $repeat) as $iteration) {
        foreach ($cases as $case) {
            $guard->lastVerdict = null;
            $rule = NotSpam::make()->withFields($case['fields'] ?? []);
            $validator = Validator::make($case['data'], ['description' => ['bail', 'required', 'string', 'max:5000', $rule]]);
            $accepted = $validator->passes();
            $verdict = $guard->lastVerdict;
            $result = $case + [
                'iteration' => $iteration,
                'accepted' => $accepted,
                'probability' => $verdict?->probability,
                'error' => $verdict?->error,
                'model' => $verdict?->model,
                'input_tokens' => $verdict?->inputTokens,
                'duration_ms' => $verdict?->durationMs,
                'review_policy_decision' => $verdict?->decision()->value,
                'outcome' => $verdict === null ? 'local_validation_rejection'
                    : (! $verdict->isAvailable() ? 'unavailable' : ($accepted ? 'accepted' : 'blocked')),
            ];
            $report['results'][] = $result;
            file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
            printf("%s [%d] %s p=%s error=%s %.0fms\n", $case['id'], $iteration, $result['outcome'], $verdict?->probability ?? 'null', $verdict?->error ?? '-', $verdict?->durationMs ?? 0);
            $consecutiveUnavailable = $verdict !== null && ! $verdict->isAvailable() ? $consecutiveUnavailable + 1 : 0;
            if ($verdict?->error === 'authentication' || $consecutiveUnavailable >= 3) {
                echo "Stopped after provider failures. Partial report: {$path}\n";
                exit(2);
            }
            usleep(200_000);
        }
    }
    $report['completed_at'] = gmdate(DATE_ATOM);
    file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    echo "Report: {$path}\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Evaluation stopped: '.get_class($exception).". No exception details printed to protect credentials.\n");
    exit(1);
}
