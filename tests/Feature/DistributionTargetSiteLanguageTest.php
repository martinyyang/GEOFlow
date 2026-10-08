<?php

namespace Tests\Feature;

use App\Models\DistributionChannel;
use App\Services\GeoFlow\DistributionTargetSitePackageBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use ZipArchive;

class DistributionTargetSiteLanguageTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            $this->removePath($path);
        }
        parent::tearDown();
    }

    public function test_site_language_normalizes_to_zh_by_default(): void
    {
        $this->assertSame('zh', DistributionChannel::normalizeSiteLanguage(null));
        $this->assertSame('zh', DistributionChannel::normalizeSiteLanguage(''));
        $this->assertSame('zh', DistributionChannel::normalizeSiteLanguage('fr'));
        $this->assertSame('en', DistributionChannel::normalizeSiteLanguage(' EN '));

        $channel = new DistributionChannel(['name' => 'Legacy', 'site_settings' => ['site_name' => 'Legacy']]);
        $this->assertSame('zh', $channel->resolvedSiteSettings()['site_language']);
    }

    public function test_default_zh_package_keeps_chinese_chrome_and_config_without_language_key(): void
    {
        $site = $this->extractPackage($this->channel([]));

        $config = (string) file_get_contents($site.'/config.php');
        $this->assertStringNotContainsString('site_language', $config);

        $staticIndex = (string) file_get_contents($site.'/index.html');
        $this->assertStringContainsString('<html lang="zh-CN">', $staticIndex);
        $this->assertStringContainsString('<title>首页 - Fetch China Blog</title>', $staticIndex);

        $home = $this->render($site, '/blog/');
        $article = $this->render($site, '/blog/article/hello');
        $missing = $this->render($site, '/blog/article/nope');

        $this->assertStringContainsString('<html lang="zh-CN">', $home);
        $this->assertStringContainsString('<title>首页 - Fetch China Blog</title>', $home);
        $this->assertStringContainsString('">首页</a></nav>', $home);
        $this->assertStringContainsString('>阅读全文</a>', $home);
        $this->assertStringContainsString('>返回首页</a>', $article);
        $this->assertStringContainsString('"position":1,"name":"首页"', $article);
        $this->assertStringContainsString('>返回首页</a>', $missing);
        foreach ([$home, $article, $missing] as $html) {
            $this->assertStringNotContainsString('Read more', $html);
            $this->assertStringNotContainsString('Back to Home', $html);
        }
    }

    public function test_english_package_has_no_chinese_home_chrome(): void
    {
        $site = $this->extractPackage($this->channel(['site_language' => 'en']));

        $config = (string) file_get_contents($site.'/config.php');
        $this->assertStringContainsString("'site_language' => 'en',", $config);

        $staticIndex = (string) file_get_contents($site.'/index.html');
        $this->assertStringContainsString('<html lang="en">', $staticIndex);
        $this->assertStringContainsString('<title>Home - Fetch China Blog</title>', $staticIndex);
        $this->assertStringNotContainsString('首页', $staticIndex);

        $home = $this->render($site, '/blog/');
        $article = $this->render($site, '/blog/article/hello');
        $missing = $this->render($site, '/blog/article/nope');

        $this->assertStringContainsString('<html lang="en">', $home);
        $this->assertStringContainsString('<title>Home - Fetch China Blog</title>', $home);
        $this->assertStringContainsString('">Home</a></nav>', $home);
        $this->assertStringContainsString('>Read more</a>', $home);
        $this->assertStringContainsString('>Back to Home</a>', $article);
        $this->assertStringContainsString('"@type":"BreadcrumbList","itemListElement":[{"@type":"ListItem","position":1,"name":"Home"', $article);
        $this->assertStringContainsString('>Back to Home</a>', $missing);
        foreach ([$staticIndex, $home, $article, $missing] as $html) {
            $this->assertStringNotContainsString('首页', $html);
            $this->assertStringNotContainsString('阅读全文', $html);
        }
    }

    public function test_synced_site_settings_can_switch_existing_zh_package_to_english(): void
    {
        $site = $this->extractPackage($this->channel([]));
        file_put_contents($site.'/storage/site-settings.json', json_encode(['site_name' => 'Fetch China Blog', 'site_language' => 'en']));

        $article = $this->render($site, '/blog/article/hello');
        $this->assertStringContainsString('"position":1,"name":"Home"', $article);
        $this->assertStringContainsString('>Back to Home</a>', $article);
        $this->assertStringNotContainsString('首页', $article);
    }

    /**
     * @param  array<string,mixed>  $extraSettings
     */
    private function channel(array $extraSettings): DistributionChannel
    {
        return DistributionChannel::query()->create([
            'name' => 'Fetch China Blog',
            'domain' => 'www.fetchchina.com',
            'endpoint_url' => 'https://www.fetchchina.com/blog',
            'channel_type' => 'geoflow_agent',
            'front_mode' => 'static',
            'template_key' => 'default',
            'site_settings' => ['site_name' => 'Fetch China Blog', 'site_description' => 'Shipping from China'] + $extraSettings,
            'status' => 'active',
        ]);
    }

    private function extractPackage(DistributionChannel $channel): string
    {
        $package = app(DistributionTargetSitePackageBuilder::class)->build($channel, 'gfk_lang', 'gfsec_lang_secret');
        $dir = sys_get_temp_dir().'/geoflow-site-language-'.uniqid();
        $this->cleanup[] = $package['path'];
        $this->cleanup[] = $dir;

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($package['path']));
        $this->assertTrue($zip->extractTo($dir));
        $zip->close();

        if (! is_dir($dir.'/storage/articles')) {
            mkdir($dir.'/storage/articles', 0777, true);
        }
        file_put_contents($dir.'/storage/articles/hello.json', json_encode(['article' => [
            'slug' => 'hello',
            'title' => 'How to ship from China',
            'content' => '<p>Body</p>',
            'excerpt' => 'Summary',
            'published_at' => '2026-09-01T00:00:00Z',
            'updated_at' => '2026-09-01T00:00:00Z',
            'category' => ['name' => 'Guides', 'slug' => 'guides'],
        ]]));

        return $dir;
    }

    private function render(string $site, string $uri): string
    {
        $process = new Process([PHP_BINARY, 'index.php'], $site.'/public', [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $uri,
        ]);
        $process->mustRun();

        return $process->getOutput();
    }

    private function removePath(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);

            return;
        }
        if (! is_dir($path)) {
            return;
        }
        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            $this->removePath($path.'/'.$entry);
        }
        rmdir($path);
    }
}
