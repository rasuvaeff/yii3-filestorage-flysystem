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

- PHP 8.3 – 8.6
- `league/flysystem` ^3.35.3 (v2 не поддерживается)
- `rasuvaeff/yii3-filestorage` ^0.10
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
        // куда filestorage:gc откладывает объекты перед удалением или
        // восстановлением; null (по умолчанию) — gc не удаляет из этого store
        'quarantinePrefix' => '.quarantine',
        // true для S3 и S3-совместимых, GCS, Azure: инвентарь читается потоком,
        // а не сортируется раз за проход gc; см. «Порядок листинга»
        'orderedListing' => true,
    ],
];
```

При сборке store проверяет привязанный `PathGeneratorInterface` (ядро биндит
`RandomPathGenerator`) на совместимость с раскладкой: генератор плоских ключей
(`group/ab/cd/<name>.jpg`) под `DirectoryLayout` по умолчанию превратил бы
удаление одного файла в удаление соседей, поэтому такая конфигурация падает при
загрузке с `InvalidConfigException`. Для таких ключей привяжите
`ObjectLayoutInterface` к `FlatSiblingLayout`.

## Что заявляется, а что нет

| Возможность | Статус |
|---|---|
| `StoreInterface` | Всегда. Запись, чтение, стриминг, удаление, exists, size, last-modified |
| `MaintenanceStoreInterface` | Всегда. Курсорная инвентаризация с временем изменения — один листинг на проход `gc` — и карантин, для `gc` / `verify` / `stat` |
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

## Раскладка и сборка мусора

```php
use Rasuvaeff\Yii3Filestorage\Store\Layout\FlatSiblingLayout;

$store = new FlysystemStore(
    name: 'flysystem',
    filesystem: $filesystem,
    streamFactory: $streamFactory,
    quarantinePrefix: '.quarantine',
    layout: new FlatSiblingLayout(), // по умолчанию: DirectoryLayout
    orderedListing: true,            // S3, GCS, Azure; см. «Порядок листинга»
);
```

| Часть | Поведение |
|---|---|
| `layout()` | `DirectoryLayout`, если не задано иное. Выбирается один раз на store: смена на существующих данных осиротит все производные |
| `delete(File)` | `DirectoryLayout`: префикс каталога ключа вместе с производными. `FlatSiblingLayout`: `<base>.<ext>` и `<base>-*` в том же каталоге, одним неглубоким листингом. Путь, который раскладка отвергает — плоский ключ вроде `blobs/ab/cd/<sha>.jpg` под `DirectoryLayout`, чей каталог общий с соседями, — даёт `StoreException`, и ничего не удаляется |
| `objects()` | `StoredObject` с `lastModified` и `size` из самого листинга (S3 `ListObjectsV2`), без запроса на объект. Адаптер без времени даёт null, и `gc` такой объект никогда не сметает. Не листит префикс карантина и пути с сегментом на `.` (`.keep`, `.well-known/`) — их не порождает ни один генератор |
| `quarantine()` | `move()` в `<prefix>/<token>/<path>` с приватной видимостью — иначе S3-копия Flysystem унесёт в карантин публичный ACL. Если move упал, уже перенесённые члены сначала возвращаются (с видимостью по умолчанию, как при restore); член, который вернуть не удалось, остаётся под записью, назван в исключении, и следующий `gc` восстановит его, пока на него ссылается строка |
| `restore()` | Put-if-absent по каждому объекту: ключ, переопубликованный за это время, сохраняется. Это `fileExists()` и затем `move()`, а на S3 move — копия плюс удаление: **не атомарно**. Корректно для того, что восстанавливает `gc`, — контентно-адресуемых ключей, где конкурирующий писатель опубликовал те же байты. Восстановленный объект получает видимость файловой системы по умолчанию, как свежая запись |
| `purge()` / `quarantined()` | Удалить запись; перечислить записи в порядке токенов (времени) |
| `quarantineProblem()` | Сообщение, если префикс не задан — тогда `gc` отказывается удалять из store |

### Порядок листинга

`gc` идёт по `objects($after, 500)` и `quarantined($after, 500)` постранично.
Страница, начинающаяся там, где кончилась предыдущая, продолжает тот же
листинг, поэтому весь проход стоит **одного** листинга бакета (один
`ListObjectsV2` на 1000 ключей), а не по одному на страницу; любой другой курсор
— прогон, продолженный через `--after`, новый процесс — начинает листинг заново
и пропускает вперёд.

| `orderedListing` | Инвентарь | Память |
|---|---|---|
| `false` (по умолчанию) | Один глубокий листинг на проход, сортируется один раз | Пропорциональна бакету, один раз на проход |
| `true` | Один глубокий листинг на проход, потоком | Постоянная |

Объявляйте `true` только для адаптера, который листит ключи в порядке `strcmp`
(побайтово): S3 и S3-совместимые, GCS, Azure — не локальный и не FTP. Ошибочный
`true` не может молча потерять объекты: каждая соседняя пара сравнивается, и
первый ключ не по порядку обрывает проход `StoreException`
(`… does not list in strcmp order …`).

Бакет принадлежит store. Всё, что лежит в нём и не является объектом store, —
файлы другого приложения — для `gc --orphans` такой же инвентарь; дайте store
отдельный бакет или префикс в нём (аргумент `$prefix` у `AwsS3V3Adapter`).

**Префикс карантина.** Путь, первый сегмент которого начинается с `.` или `_`
(`.quarantine`, `_trash/gc`). Имена групп и scope начинаются с буквы или цифры,
поэтому ни один сгенерированный ключ не может оказаться внутри префикса или
вокруг него; `write()` и `putIfAbsent()` всё равно отвергают такой ключ. Префикс
лежит в том же bucket, потому что move не пересекает файловые системы, — так что
на публичном bucket **исключите префикс из политики публичного чтения**. Store не
умеет проверять политику bucket и не утверждает обратного.

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

**Активный контент никогда не подписывается как `inline`.** Ответ S3 не может
нести `X-Content-Type-Options: nosniff`, поэтому загруженный SVG или HTML,
отданный inline из бакета на вашем домене, — это stored XSS. Каждый тип из
`Mime\ActiveMediaTypes` ядра (HTML, SVG, XML, скрипты, PDF, …) подписывается
как `attachment`, что бы ни говорила политика группы; типы, которые вы
добавляете в `-web`, можно передать и сюда:

```php
use Rasuvaeff\Yii3Filestorage\Mime\ActiveMediaTypes;

TemporaryUrlOptionsInterface::class => static fn (): TemporaryUrlOptionsInterface
    => new S3TemporaryUrlOptions(ActiveMediaTypes::withExtra(['application/x-my-script'])),
```

CR, LF и NUL из типа ответа вырезаются. `write()` также сохраняет определённый
media type как `ContentType` объекта — его и отдаёт публичный URL, — кроме
скриптуемых типов (HTML, SVG, XML, скрипты): ядро даёт им инертный ключ `.bin`,
и объект сохраняет тип этого ключа, `application/octet-stream`, так что
публичный бакет отдаёт его на скачивание. `putIfAbsent()` media type не
принимает и оставляет тип адаптеру.

**Публичный бакет `filestorage:check` не видит.** Публичным проверка считает
только `PublicFileSystemStore` ядра; ответ `publicUrl()` доказательством не
является — S3-адаптер строит URL объекта и для приватного бакета. Если бакет (или
CDN перед ним) раздаёт объекты публично, каждый объект доступен по пути, что бы
ни говорил `allowDirectPublicUrl`: исключите активные типы из `allowedMimeTypes`
всех групп, которые там хранятся.

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
        store: new FlysystemStore(
            name: 's3',
            filesystem: $filesystem,
            streamFactory: $streams,
            temporaryUrlOptions: new S3TemporaryUrlOptions(),
            quarantinePrefix: '.quarantine', // без него gc не соберёт блобы
            orderedListing: true,
        ),
        semantics: AdapterSemantics::guaranteed(),
    ),
];
```

| Флаг | Истина, когда |
|---|---|
| `atomicVisibility` | Читатель никогда не видит частично загруженное тело. Верно для S3 и S3-совместимых; неверно для обычного FTP/SFTP-адаптера, пишущего на месте |
| `immutableContentKeys` | Под content-addressed префикс не пишет никто, кроме этого пакета, и ключ никогда не перезаписывается другими байтами |

Обёртка использует `FilesystemOperator` самого обёрнутого store, так что они не
могут различаться. Генератор content-addressed ключей проверяется на
совместимость с раскладкой там, где они встречаются, — в фабрике дедупликации
`-db`, — а не здесь.

Разрешающего дефолта нет. Установка, которая не может пообещать оба пункта,
остаётся на обычном `FlysystemStore` и работает без дедупликации — полностью
функционально, просто без разделения байт. Без атомарной видимости
`fileExists()`, а потом `write()`, — это ровно та гонка, которая отдаёт второму
писателю наполовину загруженный объект и называет это попаданием в кэш.

`putIfAbsent()` переиспользует существующие байты только после проверки длины.
Расхождение длины — жёсткая ошибка: ключ *и есть* хеш, значит по этому ключу
написал кто-то посторонний.

Гарантия — **атомарная публикация одинакового содержимого**, а не единственный
создатель. Два писателя, пришедшие одновременно, оба видят, что ключа нет, оба
загружают одни и те же байты и оба получают `created: true`; читатель между
ними видит либо целый объект, либо ничего. Поэтому `created` — справочный флаг:
`false` значит, что байты уже были, `true` может достаться нескольким
конкурентным писателям, — и по нему ничего не удаляется: когда сборщик может
забрать объект, решают резервации и ссылки реестра. Эксклюзивная первая запись
(S3 `If-None-Match: *`) недоступна через конфигурацию записи Flysystem 3 и
работала бы только для одного адаптера.

## Лимиты по размеру

Удалённый `PUT` нельзя оборвать на середине, поэтому `maxBytes` группы
проверяется не во время копирования, а дважды: заведомо превышающая загрузка
отклоняется до любого I/O, а реальный размер объекта сверяется после записи, и
объект удаляется, если тело занизило свой размер. `Upload` уже ограничил тело
один раз — когда спулил не-seekable поток. Оба отказа — `UploadSizeException`
ядра (для ответа 413 ловите `SizeLimitExceededException`).

`putIfAbsent()` проверяет так же, но записанный сверх лимита объект **не**
удаляет: на этот content-ключ уже может ссылаться другой писатель. Его заберут
grace-период реестра и сборщик, если под ним ничего не закоммитится.

## Примеры

Исполняемые и самодостаточные — см. [`examples/`](examples/). Для S3-примера
нужен endpoint; хватит SeaweedFS в Docker.

## Разработка

PHP и Composer на хосте нет — всё через Docker.

```bash
make build             # validate, normalize, require-checker, cs, psalm, test
make test-integration  # против настоящего S3-endpoint; нужен доступный SeaweedFS
make cs-fix
make mutation
make release-check
```

SeaweedFS для интеграционного набора (образы MinIO удалены с Docker Hub, а
quay.io теперь требует аутентификации):

```bash
docker run -d --name weed -p 8333:8333 \
  chrislusf/seaweedfs@sha256:4e61d15fd35994cb1e43e1e553dff106794841fd9a99ade2fc8c8bfce4d7872d \
  server -dir=/data -s3
until curl -fs http://127.0.0.1:8333/ >/dev/null; do sleep 1; done
make test-integration
```

`make test-integration` сам передаёт `FILESTORAGE_S3_ENDPOINT`, `_KEY`,
`_SECRET` и `_BUCKET`, по умолчанию указывая на этот контейнер, поэтому он
никогда не скипает: если шлюз недоступен, S3-клиент не соединяется и Testo
отмечает тесты как **aborted**. Голый `composer test:integration` без
`FILESTORAGE_S3_ENDPOINT` вместо этого даёт **skipped**. Ни то, ни другое не
является успешным прогоном — смотреть надо на слово, а не на цвет: `passed`
значит, что шлюз действительно был доступен, `skipped` — что не проверено
ничего. Один тест, `aQuarantinedObjectIsPrivate`, скипается сам на SeaweedFS,
который не отдаёт ACL объектов; он работает против AWS или MinIO.

## Лицензия

BSD-3-Clause. См. [LICENSE.md](LICENSE.md).
