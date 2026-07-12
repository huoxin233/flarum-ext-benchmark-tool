# Flarum Benchmark Tool

![License](https://img.shields.io/badge/license-MIT-blue.svg) [![Latest Stable Version](https://img.shields.io/packagist/v/huoxin/benchmark-tool.svg)](https://packagist.org/packages/huoxin/benchmark-tool) [![Review](https://floxum.com/extension/huoxin/benchmark-tool/badge/review)](https://floxum.com/extension/huoxin/benchmark-tool) [![Review Score](https://floxum.com/extension/huoxin/benchmark-tool/badge/review-score)](https://floxum.com/extension/huoxin/benchmark-tool)

A headless, internal performance benchmarking tool for Flarum. 

This extension exposes the `php flarum benchmark:run` CLI command, which simulates HTTP requests, executes raw PHP/SQL, bypasses the web server stack, and records execution time and database query latency. 

**Note:** This extension is designed to be orchestrated by the [Flarum Benchmark Suite GitHub Action](https://github.com/huoxin233/flarum-benchmark-suite). It is not meant to be installed on production forums.

## Installation

Install with composer:

```sh
composer require huoxin/benchmark-tool:"*"
```

## Updating

```sh
composer update huoxin/benchmark-tool:"*"
php flarum migrate
php flarum cache:clear
```

## Usage (CLI)

```bash
php flarum benchmark:run --endpoint=/api/discussions --iterations=5
```

### Available Options
- `--endpoint=` (Default: `/api/discussions`) - The API endpoint to benchmark.
- `--method=` (Default: `GET`) - HTTP method (GET, POST, PATCH, DELETE).
- `--body=` - JSON string body for POST/PATCH requests.
- `--actor=` (Default: `1`) - User ID to execute the request as.
- `--iterations=` (Default: `5`) - Number of benchmark iterations to run.
- `--warmup` - Run an initial, unrecorded iteration to warm up the application cache, Eloquent boot traits, and OPcache.
- `--clear-cache` - Clear the Flarum Cache between every iteration.
- `--show-response` - Print the raw API response to the console.
- `--json-out=` - Output the raw performance metrics to a specific JSON file.
- `--setup-sql=` - Execute a raw SQL query before the benchmark begins.
- `--setup-php=` - Execute raw PHP code before the benchmark begins.
- `--benchmark-sql=` - Bypass the Flarum API and strictly benchmark a raw SQL query.
- `--benchmark-php=` - Bypass the Flarum API and strictly benchmark raw PHP code.

## ⚠️ Testing Production Data

It is entirely possible to install this extension on a production server to hunt for bottlenecks against millions of real posts. However, **do not run this on a live server!** 

1. **Security Risk**: The tool uses PHP's `eval()` to execute raw code via the `--benchmark-php` flag. 
2. **Data Pollution**: Running a benchmark with `--method=POST` will spam your live database with fake data.
3. **Resource Spikes**: Benchmarking intentionally hammers the CPU and Database, causing severe slowdowns for real users.

**Best Practice**: Export your production database and import it into an isolated **local staging environment**. Install the benchmark tool there to safely measure performance!

If you do not need to test against real production data and just want to run simulated benchmarks for your extension, do not install this tool manually! Instead, use the **[Flarum Benchmark Suite](https://github.com/huoxin233/flarum-benchmark-suite)** GitHub Action to automatically orchestrate everything for you.

## Links

- [Packagist](https://packagist.org/packages/huoxin/benchmark-tool)
- [GitHub](https://github.com/huoxin233/flarum-ext-benchmark-tool)
- [Discuss](https://discuss.flarum.org/d/PUT_DISCUSS_SLUG_HERE)
