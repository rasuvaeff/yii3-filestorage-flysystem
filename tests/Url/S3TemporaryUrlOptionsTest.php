<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3FilestorageFlysystem\Tests\Url;

use DateTimeImmutable;
use Rasuvaeff\Yii3Filestorage\File;
use Rasuvaeff\Yii3Filestorage\Mime\ActiveMediaTypes;
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
        $config = $this->options->for($this->deliveryOf('photo.png', forceDownload: false, mimeType: 'image/png'));

        Assert::string((string) ($config['get_object_options']['ResponseContentDisposition'] ?? ''))
            ->contains('inline; filename="photo.png"');
    }

    /**
     * PDF is an active (plugin) type since core 0.10: a presigned URL serves
     * it as an attachment even for a group whose policy says inline.
     */
    public function aPdfIsAnAttachmentWhateverThePolicySays(): void
    {
        $config = $this->options->for($this->deliveryOf('report.pdf', forceDownload: false));

        Assert::string((string) ($config['get_object_options']['ResponseContentDisposition'] ?? ''))
            ->contains('attachment; filename="report.pdf"');
    }

    /**
     * The review's probe (A5 / flysystem #2): an uploaded SVG or HTML file in
     * a group whose policy says inline was signed as `inline` — stored XSS
     * from the bucket's origin, with no way to add `nosniff` to an S3
     * response.
     */
    public function activeContentIsSignedAsAnAttachment(): void
    {
        foreach (['image/svg+xml', 'text/html', 'application/xhtml+xml', 'text/javascript'] as $type) {
            $config = $this->options->for($this->deliveryOf('x', forceDownload: false, mimeType: $type));

            Assert::string((string) ($config['get_object_options']['ResponseContentDisposition'] ?? ''))
                ->contains('attachment; filename="x"');
        }
    }

    /**
     * The same list `-web` takes extras for: an application that marks its
     * own type active gets it forced to download on a presigned URL too, not
     * only through the proxy.
     */
    public function anApplicationsExtraActiveTypeIsAnAttachmentToo(): void
    {
        $options = new S3TemporaryUrlOptions(ActiveMediaTypes::withExtra(['application/x-custom-script']));

        $config = $options->for($this->deliveryOf('x.cs', forceDownload: false, mimeType: 'Application/X-Custom-Script'));

        Assert::string((string) ($config['get_object_options']['ResponseContentDisposition'] ?? ''))
            ->contains('attachment; filename="x.cs"');
        Assert::string((string) ($this->options->for(
            $this->deliveryOf('x.cs', forceDownload: false, mimeType: 'application/x-custom-script'),
        )['get_object_options']['ResponseContentDisposition'] ?? ''))->contains('inline;');
    }

    /**
     * A stored media type is not validated beyond "non-empty" — a row this
     * package never wrote can carry a CR or LF — and S3 echoes the override
     * back as a header (review 1.0 #15).
     */
    public function theResponseTypeIsHeaderSafe(): void
    {
        $config = $this->options->for($this->deliveryOf('x', mimeType: "text/pl\r\nain\0"));

        Assert::same($config['get_object_options']['ResponseContentType'] ?? null, 'text/plain');
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

    private function deliveryOf(
        string $name,
        bool $forceDownload = true,
        string $mimeType = 'application/pdf',
    ): DeliveryOptions {
        return DeliveryOptions::fromFile(
            file: File::create(
                id: 'file-1',
                storeName: 'flysystem',
                groupName: 'common',
                relativePath: 'common/a/b/original.pdf',
                originalName: $name,
                size: 1,
                createdAt: new DateTimeImmutable('2026-01-01T00:00:00.000000+00:00'),
                mimeType: $mimeType,
            ),
            policy: new DeliveryPolicy(allowDirectPublicUrl: false, forceDownload: $forceDownload),
        );
    }
}
