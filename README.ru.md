# rasuvaeff/yii3-filestorage-flysystem

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/yii3-filestorage-flysystem/v)](https://packagist.org/packages/rasuvaeff/yii3-filestorage-flysystem)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/yii3-filestorage-flysystem/downloads)](https://packagist.org/packages/rasuvaeff/yii3-filestorage-flysystem)
[![Build](https://github.com/rasuvaeff/yii3-filestorage-flysystem/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/yii3-filestorage-flysystem/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/yii3-filestorage-flysystem/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/yii3-filestorage-flysystem/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/yii3-filestorage-flysystem/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/yii3-filestorage-flysystem/php)](https://packagist.org/packages/rasuvaeff/yii3-filestorage-flysystem)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)
[English version](README.md)

Физическая половина [`rasuvaeff/yii3-filestorage`](https://github.com/rasuvaeff/yii3-filestorage)
на Flysystem: S3, GCS, Azure, FTP, ZIP-архив — всё, для чего есть адаптер. Один
класс хранилища на всех, и возможности сообщаются результатом, а не заявляются
интерфейсом.

> Используете AI-ассистента? [llms.txt](llms.txt) — компактный справочник по API, который можно отдать модели.
> Проекты с Composer-плагином [llm/skills](https://github.com/roxblnfk/skills) получают skill пакета в `.agents/skills/` автоматически при установке.

**Статус: `0.x`.** API ещё может измениться, пока против него пишется
web-пакет.

## Требования

- PHP 8.3+
- `league/flysystem` ^3.29 (v2 не поддерживается)
- `rasuvaeff/yii3-filestorage` ^0.1
- Реализация PSR-17 и любой адаптер Flysystem

## Установка

```bash
composer require rasuvaeff/yii3-filestorage-flysystem
```

Пакет биндит `StoreInterface`. Фасад биндит ядро, половину с метаданными —
`rasuvaeff/yii3-filestorage-db`. `FilesystemOperator` биндите вы: бакет,
креденшелы и адаптер — ваша конфигурация, угадать её пакет не может.

```php
// config/common/di/filestorage.php
use Aws\S3\S3Client;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;

return [
    FilesystemOperator::class => static fn (S3Client $client): FilesystemOperator => new Filesystem(
        new AwsS3V3Adapter($client, 'my-bucket'),
    ),
];
```

```php
// config/common/params.php
return [
    'rasuvaeff/yii3-filestorage-flysystem' => [
        // попадает в File::$storeName; смена имени после появления файлов
        // осиротит все строки, которые ссылаются на старое
        'name' => 'flysystem',
    ],
];
```

## Что заявляется, а что нет

| Возможность | Статус |
|---|---|
| `StoreInterface` | Всегда. Запись, чтение, стриминг, удаление, exists, size, last-modified |
| `MaintenanceStoreInterface` | Всегда. Курсорная инвентаризация и идемпотентное удаление объекта — для `gc` / `verify` / `stat` |
| `StoreUrlProviderInterface` | Реализован всегда, но **nullable по результату**. Публичный или presigned URL, если адаптер умеет; `null`, если нет |
| `RangeReadableStoreInterface` | **Никогда** — см. ниже |
| `ContentAddressableStoreInterface` | Только через `FlysystemContentAddressableStore` и только с явным `AdapterSemantics` |

**Почему nullable-URL, а не условный интерфейс.** Один `FlysystemStore` обязан
оборачивать адаптеры, которые реально различаются, а PHP-интерфейс нельзя
реализовать условно в рантайме. Поэтому класс везде один, а различаются
*ответы*. Во Flysystem 3 URL-методы — это `@method`-аннотации («Will be added in
4.0»), а не объявления интерфейса, так что у оператора их может не быть вовсе;
хранилище проверяет наличие перед вызовом, а не падает фаталом.

**Почему нет `Range`.** У Flysystem нет примитива диапазона: `readStream()`
отдаёт объект целиком, а тело S3 не seekable. Реализовать интерфейс, вычитывая
и выбрасывая префикс, значило бы обещать дешёвую перемотку и выдавать полную
загрузку. Там, где адаптер *всё-таки* вернул seekable-поток,
`rasuvaeff/yii3-filestorage-web` это замечает и отдаёт диапазон сам; где нет —
корректный ответ полный `200`. Presigned-URL S3 обрабатывает Range внутри S3.

## Presigned-URL и политика доставки

Presigned-URL полностью минует приложение, поэтому какие заголовки повесит
объектное хранилище — такие и будут в ответе. Если политика группы требует
отдать файл вложением с известным media type, а URL этого не несёт, честный
ответ — не выдавать URL: вызывающий откатится на proxy-маршрут, который
заголовки проставит сам.

Поэтому маппинг задаётся явно, и **без него presigned-URL не выдаётся**:

```php
use Rasuvaeff\Yii3FilestorageFlysystem\Url\S3TemporaryUrlOptions;
use Rasuvaeff\Yii3FilestorageFlysystem\Url\TemporaryUrlOptionsInterface;

return [
    TemporaryUrlOptionsInterface::class => S3TemporaryUrlOptions::class,
];
```

`S3TemporaryUrlOptions` кладёт значения в `get_object_options`, которые
`AwsS3V3Adapter::temporaryUrl()` подмешивает в presigned `GetObject`, — так
`ResponseContentType` и `ResponseContentDisposition` попадают внутрь подписи, и
S3 возвращает их независимо от метаданных объекта. Имя файла оформляется по
RFC 6266: ASCII-фолбэк, безопасный к кавычкам, плюс percent-encoded
`filename*`. Для адаптера без аналога — напишите свой маппер или не биндите
ничего.

## Дедупликация

Разделять один объект между двумя логическими файлами безопасно только если
публикация атомарна, а ключ содержимого никогда не перезаписывается. Flysystem
не может ответить ни на один из этих вопросов — гарантии различаются от
адаптера к адаптеру, от конфигурации к конфигурации, иногда от политики бакета,
— поэтому их объявляет приложение, и класс отказывается существовать без обеих:

```php
use League\Flysystem\FilesystemOperator;
use Psr\Http\Message\StreamFactoryInterface;
use Rasuvaeff\Yii3Filestorage\Store\StoreInterface;
use Rasuvaeff\Yii3FilestorageFlysystem\AdapterSemantics;
use Rasuvaeff\Yii3FilestorageFlysystem\FlysystemContentAddressableStore;
use Rasuvaeff\Yii3FilestorageFlysystem\FlysystemStore;
use Rasuvaeff\Yii3FilestorageFlysystem\Url\S3TemporaryUrlOptions;

return [
    StoreInterface::class => static fn (
        FilesystemOperator $filesystem,
        StreamFactoryInterface $streams,
    ): StoreInterface => new FlysystemContentAddressableStore(
        store: new FlysystemStore('s3', $filesystem, $streams, new S3TemporaryUrlOptions()),
        filesystem: $filesystem,
        semantics: AdapterSemantics::guaranteed(),
    ),
];
```

| Флаг | Истина, когда |
|---|---|
| `atomicVisibility` | Читатель никогда не видит частично загруженное тело. Верно для S3 и S3-совместимых; неверно для обычного FTP/SFTP-адаптера, пишущего на месте |
| `immutableContentKeys` | Под content-addressed префикс не пишет никто, кроме этого пакета, и ключ никогда не перезаписывается |

Разрешающего дефолта нет. Установка, которая не может пообещать оба пункта,
остаётся на обычном `FlysystemStore` и работает без дедупликации — полностью
функционально, просто без разделения байт. Альтернатива, к которой все
тянутся, — `fileExists()`, а потом `write()`, — это ровно та гонка, которая
отдаёт второму писателю наполовину загруженный объект и называет это попаданием
в кэш.

`putIfAbsent()` переиспользует существующие байты только после проверки длины и
сообщает `created: false`, чтобы реестр записал ссылку, а не новый blob.
Расхождение длины — жёсткая ошибка: ключ *и есть* хеш, значит по этому ключу
написал кто-то посторонний.

## Лимиты по размеру

Удалённый `PUT` нельзя оборвать на середине, поэтому `maxBytes` группы
проверяется не во время копирования, а дважды: заведомо превышающая загрузка
отклоняется до любого I/O, а реальный размер объекта сверяется после записи, и
объект удаляется, если тело занизило свой размер. `Upload` уже ограничил тело
один раз — когда спулил не-seekable поток.

## Примеры

Исполняемые и самодостаточные — см. [`examples/`](examples/). Для S3-примера
нужен endpoint; хватит MinIO в Docker.

## Разработка

PHP и Composer на хосте нет — всё через Docker.

```bash
make build             # validate, normalize, require-checker, cs, psalm, test
make test-integration  # против настоящего S3-endpoint; нужен доступный MinIO
make cs-fix
make mutation
make release-check
```

## Лицензия

BSD-3-Clause. См. [LICENSE.md](LICENSE.md).
