<?php

namespace Huoxin\BenchmarkTool\Command;

use Carbon\Carbon;
use Exception;
use Flarum\Discussion\Discussion;
use Flarum\Post\Post;
use Flarum\User\Guest;
use Flarum\User\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Stream;
use Laminas\Diactoros\Uri;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Class RunBenchmarkCommand
 *
 * Executes a simulated Flarum API request and tracks database query performance.
 *
 * @package Huoxin\BenchmarkTool\Command
 */
class RunBenchmarkCommand extends Command
{
    /**
     * The console command signature.
     *
     * @var string
     */
    protected $signature = 'benchmark:run {--endpoint=/api/discussions : API Endpoint to test} {--method=GET : HTTP Method} {--body= : JSON Body for POST/PATCH} {--actor=1 : User ID to test as} {--seed-users=50 : Users to seed} {--seed-discussions=50 : Discussions to seed} {--seed-posts=10000 : Posts to seed} {--iterations=5 : Number of times to run the request} {--show-response : Output the raw API response for debugging} {--json-out= : Output results in JSON format to the specified file} {--clear-cache : Clear the application cache before each iteration} {--warmup : Run an initial iteration to warm up cache} {--setup-sql= : Raw SQL to run before benchmark} {--setup-php= : Raw PHP to run before benchmark} {--benchmark-sql= : Raw SQL to benchmark instead of API} {--benchmark-php= : Raw PHP to benchmark instead of API}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run performance benchmarks for Flarum and count database queries';

    /**
     * Execute the console command.
     *
     * @param Dispatcher $events
     * @param Container $container
     * @return int
     */
    public function handle(Dispatcher $events, Container $container): int
    {
        $endpoint = '/' . ltrim((string) $this->option('endpoint'), '/');
        $method = strtoupper((string) $this->option('method'));
        $actorId = (int) $this->option('actor');
        $jsonOut = (string) $this->option('json-out');
        $showResponse = (bool) $this->option('show-response');
        $iterations = max(1, (int) $this->option('iterations'));
        
        $seedUsers = (int) $this->option('seed-users');
        $seedDiscussions = (int) $this->option('seed-discussions');
        $seedPosts = (int) $this->option('seed-posts');
        
        $postsPerDiscussion = max(1, (int) ceil($seedPosts / max(1, $seedDiscussions)));
        $discussionsPerUser = max(1, (int) ceil($seedDiscussions / max(1, $seedUsers)));

        if (!$jsonOut) {
            $this->info("Starting Flarum Benchmark for: {$method} {$endpoint} (Running {$iterations} iterations)");
        }

        $this->seedDatabase($seedUsers, $seedDiscussions, $seedPosts, $discussionsPerUser, $postsPerDiscussion, $jsonOut !== '', $container);
        
        $setupSql = (string) $this->option('setup-sql');
        if ($setupSql !== '') {
            $container->make('db')->statement($setupSql);
        }
        
        $setupPhp = (string) $this->option('setup-php');
        if ($setupPhp !== '') {
            eval($setupPhp);
        }
        /** @var array<int, array{sql: string, time: float}> $currentQueries */
        $currentQueries = [];
        $events->listen(QueryExecuted::class, function (QueryExecuted $query) use (&$currentQueries) {
            $currentQueries[] = [
                'sql' => $query->sql,
                'time' => $query->time,
            ];
        });

        $iterationResults = [];
        $actor = User::find($actorId) ?? new Guest();

        $clearCache = (bool) $this->option('clear-cache');
        $warmup = (bool) $this->option('warmup');
        $startIteration = $warmup ? 0 : 1;

        for ($i = $startIteration; $i <= $iterations; $i++) {
            if ($i === 0 && !$jsonOut) {
                $this->info("Running Warmup Iteration (Not counted in average)...");
            }
            
            if ($clearCache) {
                $container->make('cache.store')->flush();
                // Also reset Eloquent Model boot cache if possible, though mostly it's the data cache
            }
            
            $currentQueries = [];
            // Flarum's api.handler expects the path WITHOUT the /api prefix
            $apiPath = preg_replace('/^\/api/', '', $endpoint);
            if ($apiPath === '') {
                $apiPath = '/';
            }
            
            $uri = new Uri('http://localhost' . $apiPath);
            $request = new ServerRequest([], [], $uri, $method);
            
            $body = (string) $this->option('body');
            if ($body !== '') {
                $stream = new Stream('php://memory', 'rw');
                $stream->write($body);
                $request = $request->withBody($stream)
                                   ->withParsedBody(json_decode($body, true))
                                   ->withHeader('Content-Type', 'application/json');
            }
            
            $request = $request->withAttribute('actor', $actor)
                               ->withAttribute('bypassCsrfToken', true);
            
            /** @var RequestHandlerInterface $handler */
            $handler = $container->make('flarum.api.handler');
            
            $benchmarkSql = (string) $this->option('benchmark-sql');
            $benchmarkPhp = (string) $this->option('benchmark-php');
            
            // 🔥 START TIMER: Only measure internal execution stack
            $startMemory = memory_get_usage();
            $startTime = microtime(true);

            $status = 200;
            $responseBody = "";
            try {
                if ($benchmarkSql !== '') {
                    $container->make('db')->select($benchmarkSql);
                } elseif ($benchmarkPhp !== '') {
                    eval($benchmarkPhp);
                } else {
                    $response = $handler->handle($request);
                    $status = $response->getStatusCode();
                    $responseBody = (string) $response->getBody();
                }
            } catch (Exception $e) {
                $this->error("Benchmark failed on iteration {$i}: " . $e->getMessage());
                return 1;
            }

            // 🔥 STOP TIMER
            $endTime = microtime(true);

            if (!$jsonOut) {
                if ($status >= 400) {
                    $this->error("Warning: Iteration {$i} API returned HTTP {$status}");
                    $this->error("Response Body: " . $responseBody);
                } else if ($i === 1) {
                    $this->info("Success: Iteration returned HTTP {$status}");
                    if ($showResponse && $responseBody !== '') {
                        $this->line("--- API Response Body ---");
                        $this->line($responseBody);
                        $this->line("-------------------------");
                    }
                }
            }
            
            if ($i > 0) {
                $iterationResults[] = [
                    'iteration' => $i,
                    'time_ms' => ($endTime - $startTime) * 1000,
                    'memory_mb' => memory_get_peak_usage(true) / 1024 / 1024,
                    'queries' => $currentQueries,
                    'query_count' => count($currentQueries),
                    'query_time_ms' => array_sum(array_column($currentQueries, 'time'))
                ];
            }
        }

        $this->outputResults($endpoint, $iterationResults, $jsonOut);

        return 0;
    }

    /**
     * Seeds the database with mock data if it is completely empty.
     *
     * @param int $seedUsers
     * @param int $seedDiscussions
     * @param int $seedPosts
     * @param int $discussionsPerUser
     * @param int $postsPerDiscussion
     * @param bool $isJson
     * @param Container $container
     * @return void
     */
    private function seedDatabase(int $seedUsers, int $seedDiscussions, int $seedPosts, int $discussionsPerUser, int $postsPerDiscussion, bool $isJson, Container $container): void
    {
        if (Discussion::count() > 0) {
            return;
        }

        if (!$isJson) {
            $this->info("Database is empty. Seeding {$seedPosts} posts, {$seedDiscussions} discussions, and {$seedUsers} users...");
        }
        
        $usersData = [];
        // Start from ID 2 because Flarum install.yml creates the Admin as ID 1
        for ($u = 2; $u <= $seedUsers + 1; $u++) {
            $usersData[] = [
                'id' => $u, 
                'username' => "tester{$u}", 
                'email' => "test{$u}@test.com", 
                'is_email_confirmed' => 1,
                'joined_at' => Carbon::now(),
                'password' => 'password123',
            ];
        }
        User::insert($usersData);
        
        $db = $container->make('db');
        
        // Flarum 1.x may not support insertOrIgnore on all drivers, so we do a standard check
        $exists = $db->table('group_user')->where('user_id', 1)->where('group_id', 1)->exists();
        if (!$exists) {
            $db->table('group_user')->insert(['user_id' => 1, 'group_id' => 1]);
        }
        
        $schema = $db->getSchemaBuilder();
        $hasApproved = $schema->hasColumn('discussions', 'is_approved');
        $hasSticky = $schema->hasColumn('discussions', 'is_sticky');
        $hasPrivate = $schema->hasColumn('discussions', 'is_private');
        $hasSlug = $schema->hasColumn('discussions', 'slug');
        
        $discussionsData = [];
        $postsData = [];
        $discussionId = 1;
        $postId = 1;
        
        // Distribute discussions across all users (1 to seedUsers+1)
        for ($u = 1; $u <= $seedUsers + 1; $u++) {
            $userDiscs = min($discussionsPerUser, $seedDiscussions - $discussionId + 1);
            if ($userDiscs <= 0) {
                break;
            }
            
            for ($i = 1; $i <= $userDiscs; $i++) {
                
                for ($j = 1; $j <= $postsPerDiscussion; $j++) {
                    $post = [
                        'id' => $postId,
                        'discussion_id' => $discussionId,
                        'number' => $j,
                        'user_id' => $u,
                        'type' => 'comment',
                        'content' => "This is a benchmark post number $j for discussion $discussionId.",
                        'created_at' => Carbon::now(),
                    ];
                    
                    if ($schema->hasColumn('posts', 'is_approved')) {
                        $post['is_approved'] = 1;
                    }
                    
                    $postsData[] = $post;
                    $postId++;
                }
                
                $discussion = [
                    'id' => $discussionId,
                    'title' => "Benchmark Discussion $discussionId", 
                    'user_id' => $u, 
                    'comment_count' => $postsPerDiscussion,
                    'participant_count' => 1,
                    'last_posted_at' => Carbon::now(),
                    'last_posted_user_id' => $u,
                    'created_at' => Carbon::now(),
                ];
                
                if ($hasApproved) $discussion['is_approved'] = 1;
                if ($hasSticky) $discussion['is_sticky'] = 0;
                if ($hasPrivate) $discussion['is_private'] = 0;
                if ($hasSlug) $discussion['slug'] = "benchmark-discussion-$discussionId";
                
                $discussionsData[] = $discussion;
                $discussionId++;
            }
        }
        
        foreach (array_chunk($discussionsData, 1000) as $chunk) {
            Discussion::insert($chunk);
        }
        foreach (array_chunk($postsData, 1000) as $chunk) {
            Post::insert($chunk);
        }
        
        // Resolve circular dependencies mathematically to avoid Postgres foreign key constraint errors
        $db->statement("UPDATE discussions SET first_post_id = (id - 1) * {$postsPerDiscussion} + 1, last_post_id = id * {$postsPerDiscussion}");
        
        if (!$isJson) {
            $this->info("Finished seeding data.");
        }
    }

    /**
     * Outputs the benchmark results.
     *
     * @param string $endpoint
     * @param array $iterationResults
     * @param string $jsonOut
     * @return void
     */
    private function outputResults(string $endpoint, array $iterationResults, string $jsonOut): void
    {
        $avgTime = array_sum(array_column($iterationResults, 'time_ms')) / count($iterationResults);
        $avgQueries = array_sum(array_column($iterationResults, 'query_count')) / count($iterationResults);
        $avgQueryTime = array_sum(array_column($iterationResults, 'query_time_ms')) / count($iterationResults);
        $avgMemory = array_sum(array_column($iterationResults, 'memory_mb')) / count($iterationResults);
        $avgPhpCpuTime = $avgTime - $avgQueryTime;
        $lastResult = end($iterationResults); // Use the final run for the deep query inspect

        if ($jsonOut !== '') {
            file_put_contents($jsonOut, json_encode([
                'endpoint' => $endpoint,
                'avg_time_ms' => round($avgTime, 2),
                'avg_queries' => round($avgQueries, 2),
                'avg_query_time_ms' => round($avgQueryTime, 2),
                'avg_memory_mb' => round($avgMemory, 2),
                'avg_php_cpu_time_ms' => round($avgPhpCpuTime, 2),
                'iterations' => $iterationResults
            ], JSON_PRETTY_PRINT));
        }

        // Output to Terminal
        if (!$jsonOut) {
            $this->table(
            ['Performance Metric', 'Average Value'],
            [
                ['Total API Response Time', round($avgTime, 2) . ' ms'],
                ['Database Queries Executed', round($avgQueries, 2)],
                ['Time Spent in Database', round($avgQueryTime, 2) . ' ms'],
                ['Peak PHP Memory Usage', round($avgMemory, 2) . ' MB'],
                ['Time Spent Executing PHP', round($avgPhpCpuTime, 2) . ' ms'],
            ]
            );
        }

        if (!$jsonOut && $lastResult['query_count'] > 0) {
            $this->info("\nTop 3 Slowest Queries (from final iteration):");
            $queries = $lastResult['queries'];
            usort($queries, function (array $a, array $b) {
                return $b['time'] <=> $a['time'];
            });
            $slowest = array_slice($queries, 0, 3);
            foreach ($slowest as $q) {
                $this->line("- [{$q['time']}ms] {$q['sql']}");
            }
        }
    }
}
