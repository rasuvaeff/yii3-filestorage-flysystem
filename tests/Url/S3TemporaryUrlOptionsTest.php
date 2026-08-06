<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageFlysystem\Tests\Url;

use DateTimeImmutable;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryOptions;
use Rasuvaeff\Yii3Filestorage\Policy\DeliveryPolicy;
use Rasuvaeff\Yii3FilestorageFlysystem\Url\S3TemporaryUrlOptions;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(S3TemporaryUrlOptions::class)]
final class S3TemporaryUrlOptionsTest
{
    private S3TemporaryUrlOptions $options;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->options = new S3TemporaryUrlOptions();
    }

    public function mapsOntoTheKeyTheS3AdapterReads(): void
    {
        $config = $this->options->for($this->deliveryOf('report.pdf'));

        Assert::same($config['get_object_options']['ResponseContentType'] ?? null, 'application/pdf');
        Assert::same(
            $config['get_object_options']['ResponseContentDisposition'] ?? null,
            'attachment; filename="report.pdf"; filename*=UTF-8\'\'report.pdf',
        );
    }

    public function inlineDeliveryIsSpelledOut(): void
    {
        $config = $this->options->for($this->deliveryOf('report.pdf', forceDownload: false));

        Assert::string((string) ($config['get_object_options']['ResponseContentDisposition'] ?? ''))
            ->contains('inline; filename="report.pdf"');
    }

    /**
     * A quote in the filename would close the parameter early and let the rest
     * of the name become header syntax. The signed URL is a header the object
     * store echoes back, so this is not cosmetic.
     */
    public function aQuoteInTheNameCannotEscapeTheParameter(): void
    {
        $config = $this->options->for($this->deliveryOf('we"ird\\name.pdf'));
        $disposition = (string) ($config['get_object_options']['ResponseContentDisposition'] ?? '');

        Assert::same(substr_count($disposition, '"'), 2);
        Assert::string($disposition)->contains('filename="we_ird_name.pdf"');
    }

    /**
     * Non-ASCII survives in `filename*`, which is what RFC 6266 has it for, and
     * the ASCII fallback stays a legible placeholder rather than mojibake.
     */
    public function nonAsciiNamesTravelInTheEncodedParameter(): void
    {
        $config = $this->options->for($this->deliveryOf('отчёт.pdf'));
        $disposition = (string) ($config['get_object_options']['ResponseContentDisposition'] ?? '');

        Assert::string($disposition)->contains("filename*=UTF-8''%D0%BE%D1%82%D1%87%D1%91%D1%82.pdf");
        Assert::string($disposition)->contains('filename="_____.pdf"');
    }

    private function deliveryOf(string $name, bool $forceDownload = true): DeliveryOptions
    {
        return DeliveryOptions::fromFile(
            file: File::create(
                id: 'file-1',
                storeName: 'flysystem',
                groupName: 'common',
                relativePath: 'common/a/b/original.pdf',
                originalName: $name,
                size: 1,
                createdAt: new DateTimeImmutable('2026-01-01T00:00:00.000000+00:00'),
                mimeType: 'application/pdf',
            ),
            policy: new DeliveryPolicy(allowDirectPublicUrl: false, forceDownload: $forceDownload),
        );
    }
}
