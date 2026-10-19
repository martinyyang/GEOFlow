<?php

namespace Tests\Feature;

use App\Models\DistributionChannel;
use App\Models\DistributionChannelSecret;
use App\Services\GeoFlow\DistributionTargetSitePackageBuilder;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use ZipArchive;

class DistributionTargetSiteI18nHeadTest extends TestCase
{
    use RefreshDatabase;

    public function test_target_site_package_contains_i18n_and_head_code(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is not available.');
        }

        $channel = DistributionChannel::query()->create([
            'name' => 'Package Assertions Site',
            'domain' => '127.0.0.1',
            'endpoint_url' => 'http://127.0.0.1:8080/blog',
            'channel_type' => 'geoflow_agent',
            'front_mode' => 'rewrite',
            'template_key' => 'default',
            'site_settings' => [
                'site_name' => 'Assert Blog',
                'per_page' => 2,
                'site_language' => 'en',
            ],
            'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'gfk_i18n_assert',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_i18n_assert'),
            'status' => 'active',
            'scopes' => ['frontend.capabilities'],
        ]);

        $package = app(DistributionTargetSitePackageBuilder::class)->build($channel, 'gfk_i18n_assert', 'gfsec_i18n_assert');
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($package['path']));

        $frontController = (string) $zip->getFromName('public/index.php');

        $this->assertStringContainsString('function listText', $frontController);
        $this->assertStringContainsString('function normalizeHtmlLangValue', $frontController);
        $this->assertStringContainsString('function htmlLang', $frontController);
        $this->assertStringContainsString('function rawSiteSettings', $frontController);
        $this->assertStringContainsString('supports_head_requests', $frontController);
        $this->assertStringContainsString('$isHead = $method === \'HEAD\';', $frontController);

        $zip->close();
        if (is_file($package['path'])) {
            unlink($package['path']);
        }
    }

    public function test_english_site_pagination_and_head_requests(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is not available.');
        }

        $port = $this->freeTcpPort();
        $baseUrl = 'http://127.0.0.1:'.$port;
        $endpointUrl = $baseUrl.'/blog';
        $extractPath = sys_get_temp_dir().'/geoflow-i18n-en-'.uniqid();

        $channel = DistributionChannel::query()->create([
            'name' => 'English Blog',
            'domain' => '127.0.0.1',
            'endpoint_url' => $endpointUrl,
            'channel_type' => 'geoflow_agent',
            'front_mode' => 'rewrite',
            'template_key' => 'default',
            'site_settings' => [
                'site_name' => 'English Blog',
                'per_page' => 2,
                'site_language' => 'en',
            ],
            'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'gfk_en',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_en_secret'),
            'status' => 'active',
            'scopes' => ['frontend.capabilities'],
        ]);

        $package = app(DistributionTargetSitePackageBuilder::class)->build($channel, 'gfk_en', 'gfsec_en_secret');
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($package['path']));
        $this->assertTrue($zip->extractTo($extractPath));
        $zip->close();

        // Write site-settings.json with per_page = 2 and site_language = 'en'
        file_put_contents($extractPath.'/storage/site-settings.json', json_encode([
            'site_name' => 'English Blog',
            'per_page' => 2,
            'site_language' => 'en',
        ], JSON_UNESCAPED_UNICODE));

        // Write 5 articles
        $articlesDir = $extractPath.'/storage/articles';
        $this->writeArticleJson($articlesDir, 'article-5-newest', 'Article 5 Newest', '2026-01-05 10:00:00');
        $this->writeArticleJson($articlesDir, 'article-4', 'Article 4', '2026-01-04 10:00:00');
        $this->writeArticleJson($articlesDir, 'article-3', 'Article 3', '2026-01-03 10:00:00');
        $this->writeArticleJson($articlesDir, 'article-2', 'Article 2', '2026-01-02 10:00:00');
        $this->writeArticleJson($articlesDir, 'article-1-oldest', 'Article 1 Oldest', '2026-01-01 10:00:00');

        file_put_contents($extractPath.'/router.php', <<<'PHP'
<?php
require __DIR__ . '/public/index.php';
PHP
        );

        $server = new Process([PHP_BINARY, '-S', '127.0.0.1:'.$port, 'router.php'], $extractPath);
        $server->start();
        config(['geoflow.outbound_private_targets' => ['127.0.0.1:'.$port]]);

        try {
            $this->waitForHttpServer($endpointUrl);

            // a) English site assertions
            $page2Response = Http::timeout(3)->get($endpointUrl.'/page/2');
            $this->assertSame(200, $page2Response->status());
            $page2Html = $page2Response->body();

            $this->assertStringContainsString('<html lang="en"', $page2Html);
            $this->assertStringContainsString('<title>Blog - Page 2', $page2Html);
            $this->assertStringContainsString('>Previous<', $page2Html);
            $this->assertStringContainsString('>Next<', $page2Html);
            $this->assertStringContainsString('aria-label="Pagination"', $page2Html);
            $this->assertStringContainsString('aria-label="Page 1"', $page2Html);
            $this->assertStringContainsString('aria-label="Page 3"', $page2Html);
            $this->assertStringNotContainsString('上一页', $page2Html);
            $this->assertStringNotContainsString('下一页', $page2Html);
            $this->assertStringNotContainsString('首页', $page2Html);
            $this->assertStringNotContainsString('第 ', $page2Html);

            $page1Response = Http::timeout(3)->get($endpointUrl.'/');
            $this->assertSame(200, $page1Response->status());
            $page1Html = $page1Response->body();
            $this->assertStringContainsString('<html lang="en"', $page1Html);
            $this->assertStringContainsString('aria-label="Page 2"', $page1Html);

            $page9Response = Http::timeout(3)->get($endpointUrl.'/page/9');
            $this->assertSame(404, $page9Response->status());
            $this->assertStringContainsString('Page not found', $page9Response->body());

            // d) HEAD assertions on English site
            $headRoutes = [
                ['/blog/', 200],
                ['/blog/page/2', 200],
                ['/blog/article/article-5-newest', 200],
                ['/blog/page/9', 404],
                ['/blog/article/does-not-exist', 404],
                ['/blog/page/1', 301],
                ['/blog/llms.txt', 200],
                ['/blog/sitemap.txt', 200],
            ];

            foreach ($headRoutes as [$path, $expectedStatus]) {
                $url = $baseUrl.$path;
                $getResponse = Http::withoutRedirecting()->timeout(3)->get($url);
                $headResponse = Http::withoutRedirecting()->timeout(3)->send('HEAD', $url);

                $this->assertSame($expectedStatus, $headResponse->status(), "HEAD {$path} status mismatch");
                $this->assertSame($getResponse->status(), $headResponse->status(), "HEAD vs GET {$path} status mismatch");
                $this->assertSame('', $headResponse->body(), "HEAD {$path} body must be empty");
                $this->assertSame($getResponse->header('Content-Type'), $headResponse->header('Content-Type'), "HEAD vs GET {$path} Content-Type mismatch");

                if ($expectedStatus === 301) {
                    $this->assertSame('/blog/', $headResponse->header('Location'));
                }
            }
        } finally {
            $server->stop(0);
            if (is_file($package['path'])) {
                unlink($package['path']);
            }
            $this->removeDirectory($extractPath);
        }
    }

    public function test_chinese_site_pagination(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is not available.');
        }

        $port = $this->freeTcpPort();
        $baseUrl = 'http://127.0.0.1:'.$port;
        $endpointUrl = $baseUrl.'/blog';
        $extractPath = sys_get_temp_dir().'/geoflow-i18n-zh-'.uniqid();

        $channel = DistributionChannel::query()->create([
            'name' => 'Chinese Blog',
            'domain' => '127.0.0.1',
            'endpoint_url' => $endpointUrl,
            'channel_type' => 'geoflow_agent',
            'front_mode' => 'rewrite',
            'template_key' => 'default',
            'site_settings' => [
                'site_name' => 'Chinese Blog',
                'per_page' => 2,
            ],
            'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'gfk_zh',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_zh_secret'),
            'status' => 'active',
            'scopes' => ['frontend.capabilities'],
        ]);

        $package = app(DistributionTargetSitePackageBuilder::class)->build($channel, 'gfk_zh', 'gfsec_zh_secret');
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($package['path']));
        $this->assertTrue($zip->extractTo($extractPath));
        $zip->close();

        // Write site-settings.json with per_page = 2 and no site_language
        file_put_contents($extractPath.'/storage/site-settings.json', json_encode([
            'site_name' => 'Chinese Blog',
            'per_page' => 2,
        ], JSON_UNESCAPED_UNICODE));

        // Write 5 articles
        $articlesDir = $extractPath.'/storage/articles';
        $this->writeArticleJson($articlesDir, 'article-5-newest', 'Article 5 Newest', '2026-01-05 10:00:00');
        $this->writeArticleJson($articlesDir, 'article-4', 'Article 4', '2026-01-04 10:00:00');
        $this->writeArticleJson($articlesDir, 'article-3', 'Article 3', '2026-01-03 10:00:00');
        $this->writeArticleJson($articlesDir, 'article-2', 'Article 2', '2026-01-02 10:00:00');
        $this->writeArticleJson($articlesDir, 'article-1-oldest', 'Article 1 Oldest', '2026-01-01 10:00:00');

        file_put_contents($extractPath.'/router.php', <<<'PHP'
<?php
require __DIR__ . '/public/index.php';
PHP
        );

        $server = new Process([PHP_BINARY, '-S', '127.0.0.1:'.$port, 'router.php'], $extractPath);
        $server->start();
        config(['geoflow.outbound_private_targets' => ['127.0.0.1:'.$port]]);

        try {
            $this->waitForHttpServer($endpointUrl);

            // b) Chinese site assertions
            $page2Response = Http::timeout(3)->get($endpointUrl.'/page/2');
            $this->assertSame(200, $page2Response->status());
            $page2Html = $page2Response->body();

            $this->assertStringContainsString('<html lang="zh-CN"', $page2Html);
            $this->assertStringContainsString('首页 - 第 2 页', $page2Html);
            $this->assertStringContainsString('>上一页<', $page2Html);
            $this->assertStringContainsString('>下一页<', $page2Html);
            $this->assertStringContainsString('aria-label="分页"', $page2Html);
            $this->assertStringContainsString('aria-label="第 1 页"', $page2Html);

            $page9Response = Http::timeout(3)->get($endpointUrl.'/page/9');
            $this->assertSame(404, $page9Response->status());
            $this->assertStringContainsString('页面不存在。', $page9Response->body());
        } finally {
            $server->stop(0);
            if (is_file($package['path'])) {
                unlink($package['path']);
            }
            $this->removeDirectory($extractPath);
        }
    }

    public function test_html_lang_only_site_pagination(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is not available.');
        }

        $port = $this->freeTcpPort();
        $baseUrl = 'http://127.0.0.1:'.$port;
        $endpointUrl = $baseUrl.'/blog';
        $extractPath = sys_get_temp_dir().'/geoflow-i18n-htmllang-'.uniqid();

        $channel = DistributionChannel::query()->create([
            'name' => 'HtmlLang Blog',
            'domain' => '127.0.0.1',
            'endpoint_url' => $endpointUrl,
            'channel_type' => 'geoflow_agent',
            'front_mode' => 'rewrite',
            'template_key' => 'default',
            'site_settings' => [
                'site_name' => 'HtmlLang Blog',
                'per_page' => 2,
            ],
            'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'gfk_htmllang',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_htmllang_secret'),
            'status' => 'active',
            'scopes' => ['frontend.capabilities'],
        ]);

        $package = app(DistributionTargetSitePackageBuilder::class)->build($channel, 'gfk_htmllang', 'gfsec_htmllang_secret');
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($package['path']));
        $this->assertTrue($zip->extractTo($extractPath));
        $zip->close();

        // c) html_lang-only site: storage/site-settings.json {"html_lang":"en","per_page":2} with no site_language
        file_put_contents($extractPath.'/storage/site-settings.json', json_encode([
            'html_lang' => 'en',
            'per_page' => 2,
        ], JSON_UNESCAPED_UNICODE));

        // Write 5 articles
        $articlesDir = $extractPath.'/storage/articles';
        $this->writeArticleJson($articlesDir, 'article-5-newest', 'Article 5 Newest', '2026-01-05 10:00:00');
        $this->writeArticleJson($articlesDir, 'article-4', 'Article 4', '2026-01-04 10:00:00');
        $this->writeArticleJson($articlesDir, 'article-3', 'Article 3', '2026-01-03 10:00:00');
        $this->writeArticleJson($articlesDir, 'article-2', 'Article 2', '2026-01-02 10:00:00');
        $this->writeArticleJson($articlesDir, 'article-1-oldest', 'Article 1 Oldest', '2026-01-01 10:00:00');

        file_put_contents($extractPath.'/router.php', <<<'PHP'
<?php
require __DIR__ . '/public/index.php';
PHP
        );

        $server = new Process([PHP_BINARY, '-S', '127.0.0.1:'.$port, 'router.php'], $extractPath);
        $server->start();
        config(['geoflow.outbound_private_targets' => ['127.0.0.1:'.$port]]);

        try {
            $this->waitForHttpServer($endpointUrl);

            // /blog/ has '<html lang="en"'
            $page1Response = Http::timeout(3)->get($endpointUrl.'/');
            $this->assertSame(200, $page1Response->status());
            $this->assertStringContainsString('<html lang="en"', $page1Response->body());

            // /blog/page/2 has '>Next<' or '>Previous<' (English)
            $page2Response = Http::timeout(3)->get($endpointUrl.'/page/2');
            $this->assertSame(200, $page2Response->status());
            $page2Html = $page2Response->body();
            $this->assertTrue(str_contains($page2Html, '>Next<') || str_contains($page2Html, '>Previous<'));
            $this->assertStringContainsString('>Previous<', $page2Html);
            $this->assertStringContainsString('>Next<', $page2Html);
        } finally {
            $server->stop(0);
            if (is_file($package['path'])) {
                unlink($package['path']);
            }
            $this->removeDirectory($extractPath);
        }
    }

    public function test_config_html_lang_survives_empty_synced_html_lang(): void
    {
        if (! class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is not available.');
        }

        $extractPath = sys_get_temp_dir().'/geoflow-i18n-config-htmllang-'.uniqid();
        $channel = DistributionChannel::query()->create([
            'name' => 'Config HtmlLang Blog',
            'domain' => '127.0.0.1',
            'endpoint_url' => 'http://127.0.0.1:8080/blog',
            'channel_type' => 'geoflow_agent',
            'front_mode' => 'rewrite',
            'template_key' => 'default',
            'site_settings' => ['site_name' => 'Config HtmlLang Blog', 'per_page' => 2],
            'status' => 'active',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'gfk_cfg_htmllang',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_cfg_htmllang'),
            'status' => 'active',
            'scopes' => ['frontend.capabilities'],
        ]);

        $package = app(DistributionTargetSitePackageBuilder::class)->build($channel, 'gfk_cfg_htmllang', 'gfsec_cfg_htmllang');
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($package['path']));
        $this->assertTrue($zip->extractTo($extractPath));
        $zip->close();

        try {
            // Hand-configured html_lang in config.php (as on a hotfixed live site) ...
            $configFile = $extractPath.'/config.php';
            $configPhp = (string) file_get_contents($configFile);
            $this->assertStringContainsString("return [\n", $configPhp);
            file_put_contents($configFile, str_replace("return [\n", "return [\n    'html_lang' => 'en',\n", $configPhp));
            // ... must not be masked by an empty html_lang written by a settings sync.
            file_put_contents($extractPath.'/storage/site-settings.json', json_encode(['html_lang' => '', 'per_page' => 2]));
            foreach (['a5' => '2026-01-05', 'a4' => '2026-01-04', 'a3' => '2026-01-03'] as $slug => $date) {
                $this->writeArticleJson($extractPath.'/storage/articles', $slug, 'Title '.$slug, $date.' 10:00:00');
            }

            $runnerFile = $extractPath.'/runner.php';
            file_put_contents($runnerFile, <<<'PHP'
<?php
$_SERVER['REQUEST_METHOD'] = 'CLI-TEST';
$_SERVER['REQUEST_URI'] = '/__no_route__';
$config = require __DIR__ . '/config.php';
ob_start();
require __DIR__ . '/public/index.php';
ob_end_clean();
echo renderHomePageHtml($config, 2);
PHP
            );
            $proc = new Process([PHP_BINARY, $runnerFile], $extractPath);
            $proc->mustRun();
            $html = $proc->getOutput();

            $this->assertStringContainsString('<html lang="en"', $html);
            $this->assertStringContainsString('<title>Blog - Page 2', $html);
            $this->assertStringContainsString('>Previous<', $html);
        } finally {
            if (is_file($package['path'])) {
                unlink($package['path']);
            }
            $this->removeDirectory($extractPath);
        }
    }

    private function writeArticleJson(string $dir, string $slug, string $title, string $publishedAt): void
    {
        $data = [
            'article' => [
                'slug' => $slug,
                'title' => $title,
                'published_at' => $publishedAt,
                'excerpt' => 'Summary of '.$title,
                'content' => 'Content of '.$title,
                'content_html' => '<p>Content of '.$title.'</p>',
            ],
        ];
        file_put_contents($dir.'/'.$slug.'.json', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private function freeTcpPort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($server, $errstr);
        $address = (string) stream_socket_get_name($server, false);
        fclose($server);

        return (int) substr(strrchr($address, ':'), 1);
    }

    private function waitForHttpServer(string $baseUrl): void
    {
        for ($attempt = 0; $attempt < 50; $attempt++) {
            try {
                $response = Http::timeout(1)->get(rtrim($baseUrl, '/').'/');
                if ($response->status() < 500) {
                    return;
                }
            } catch (\Throwable) {
                usleep(100000);
            }
        }

        $this->fail('PHP runtime target server did not start.');
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $item->isDir() ? rmdir((string) $item->getPathname()) : unlink((string) $item->getPathname());
        }

        rmdir($path);
    }
}
