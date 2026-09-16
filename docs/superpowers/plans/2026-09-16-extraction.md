# doccum Extraction & OCR Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn uploaded documents into text — reading PDFs that have a text layer, OCR'ing those that do not, and unpacking Office formats — so search has something to search.

**Architecture:** A chain of small `TextExtractor` strategies, each declaring which MIME types it handles. External tools are invoked through one injectable runner, so the decision logic is testable on a machine that has none of them installed. Extraction runs on the `ingest` queue and records its outcome — including failure — against the file version, so a document is never silently unsearchable.

**Tech Stack:** Laravel 13, Symfony Process, poppler-utils, tesseract-ocr, Pest 5.

**Spec:** `docs/superpowers/specs/2026-09-15-doccum-design.md` §7 (text extraction), §7a (OCR providers)

**Previous plans:** foundation, access-control, storage, installer, embedded-storage, properties — all merged.

## Global Constraints

- **Never edit anything under `vendor/`;** never edit a framework migration.
- `declare(strict_types=1);` on every PHP file authored, migrations included.
- Models use `#[Fillable([...])]`.
- Nothing outside `App\Services\DocumentStorage` resolves a disk (`ConnectionProbe` excepted; tests may `Storage::fake`).
- **Never pass an interface to `toThrow()`** — Pest branches on `class_exists()`, false for interfaces.
- **No test may require an external binary to be installed.** This machine has `pdftotext` and `pdftoppm` but NOT `tesseract`; the container has all three. Every strategy calls out through `App\Support\ProcessRunner`, which tests fake. Tests that genuinely exercise a real binary must `skip()` when it is absent, and must never be the only coverage of a behaviour.
- Pest for all tests; each task ends with a green FULL suite and its own commit.
- Baseline entering this plan: **291 tests, 640 assertions**.

---

## File Structure

| Path | Responsibility |
|---|---|
| `app/Support/ProcessRunner.php` | The single place an external command is executed. Fakeable. |
| `app/Models/FileText.php` | Extracted text for one file version, with its status. |
| `app/Extraction/TextExtractor.php` | The strategy interface. |
| `app/Extraction/ExtractionResult.php` | Text, extractor name, status, error. |
| `app/Extraction/ExtractorChain.php` | Picks the strategy for a MIME type. |
| `app/Extraction/Strategies/*.php` | Plain, Pdf, Ocr, OfficeXml, Unsupported. |
| `app/Jobs/ExtractText.php` | Runs the chain on the `ingest` queue and records the outcome. |
| `app/Console/Commands/ExtractRetry.php` | Re-queue failures without re-uploading. |

---

### Task 1: The process runner

**Files:**
- Create: `app/Support/ProcessRunner.php`, `app/Support/ProcessResult.php`
- Test: `tests/Feature/ProcessRunnerTest.php`

**Interfaces:**
- `ProcessRunner::run(array $command, ?int $timeout = null): ProcessResult`
- `ProcessRunner::available(string $binary): bool`
- `ProcessRunner::fake(array $responses): void` / `::assertRan(string $binaryFragment)`
- `ProcessResult` — readonly `bool $ok`, `string $output`, `string $error`, `int $exitCode`

Every external tool goes through here. That is what lets the extraction logic be
tested on a machine with no OCR installed, and it means a timeout or a missing
binary is handled in one place rather than five.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Support\ProcessRunner;

it('runs a real command', function () {
    $result = app(ProcessRunner::class)->run(['echo', 'hello']);

    expect($result->ok)->toBeTrue()
        ->and(trim($result->output))->toBe('hello')
        ->and($result->exitCode)->toBe(0);
});

it('reports a failing command without throwing', function () {
    $result = app(ProcessRunner::class)->run(['sh', '-c', 'echo boom >&2; exit 3']);

    expect($result->ok)->toBeFalse()
        ->and($result->exitCode)->toBe(3)
        ->and(trim($result->error))->toBe('boom');
});

it('reports a missing binary as failure rather than an exception', function () {
    // Extraction must degrade to "unsupported", never crash the worker,
    // when an optional tool is not installed.
    $result = app(ProcessRunner::class)->run(['definitely-not-a-real-binary-xyz']);

    expect($result->ok)->toBeFalse()
        ->and($result->error)->not->toBeEmpty();
});

it('knows whether a binary is available', function () {
    expect(app(ProcessRunner::class)->available('sh'))->toBeTrue()
        ->and(app(ProcessRunner::class)->available('definitely-not-a-real-binary-xyz'))->toBeFalse();
});

it('can be faked, keyed by the binary', function () {
    ProcessRunner::fake([
        'pdftotext' => ['output' => 'faked text'],
        'tesseract' => ['output' => 'ocr text', 'exitCode' => 0],
    ]);

    expect(app(ProcessRunner::class)->run(['pdftotext', '-', '-'])->output)->toBe('faked text')
        ->and(app(ProcessRunner::class)->run(['tesseract', 'a', 'b'])->output)->toBe('ocr text');
});

it('fails a faked binary that was not configured', function () {
    ProcessRunner::fake(['pdftotext' => ['output' => 'x']]);

    expect(app(ProcessRunner::class)->run(['tesseract'])->ok)->toBeFalse();
});

it('records what it ran so a test can assert on it', function () {
    ProcessRunner::fake(['pdftotext' => ['output' => 'x']]);
    app(ProcessRunner::class)->run(['pdftotext', '-layout', '/tmp/a.pdf', '-']);

    app(ProcessRunner::class)->assertRan('pdftotext');
    expect(fn () => app(ProcessRunner::class)->assertRan('tesseract'))
        ->toThrow(PHPUnit\Framework\AssertionFailedError::class);
});
```

- [ ] **Step 2: Run and watch it fail**

- [ ] **Step 3: Write both classes**

`ProcessRunner` wraps Symfony Process, catching every throwable into a failed
`ProcessResult`. Bind it as a singleton in `DoccumServiceProvider` so `fake()`
state is shared within a test.

- [ ] **Step 4: Run focused, then full suite. Commit.**

---

### Task 2: Storing extracted text

**Files:**
- Create: migration, `app/Models/FileText.php`, `database/factories/FileTextFactory.php`, `app/Enums/ExtractionStatus.php`
- Modify: `app/Models/FileVersion.php`, `app/Models/File.php`
- Test: `tests/Feature/FileTextTest.php`

**Interfaces:**
- `ExtractionStatus` — `Pending`, `Processing`, `Done`, `Failed`, `Unsupported`
- `FileVersion::text()` — hasOne
- `File::extractedText(): ?string` — text of the current version

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Enums\ExtractionStatus;
use App\Models\FileText;
use App\Models\FileVersion;

it('stores text against one version', function () {
    $version = FileVersion::factory()->create();

    FileText::create([
        'file_version_id' => $version->id,
        'status' => ExtractionStatus::Done,
        'extractor' => 'pdftotext',
        'text' => 'the contents',
        'chars' => 12,
    ]);

    expect($version->fresh()->text->text)->toBe('the contents')
        ->and($version->fresh()->text->status)->toBe(ExtractionStatus::Done);
});

it('holds one row per version', function () {
    $version = FileVersion::factory()->create();
    FileText::factory()->for($version, 'version')->create();

    expect(fn () => FileText::factory()->for($version, 'version')->create())
        ->toThrow(Illuminate\Database\QueryException::class);
});

it('records a failure with its reason', function () {
    $version = FileVersion::factory()->create();

    FileText::create([
        'file_version_id' => $version->id,
        'status' => ExtractionStatus::Failed,
        'error' => 'pdftotext exited 1',
    ]);

    expect($version->fresh()->text->status)->toBe(ExtractionStatus::Failed)
        ->and($version->fresh()->text->error)->toContain('pdftotext');
});

it('exposes the current version text through the file', function () {
    $version = FileVersion::factory()->create();
    $file = $version->file;
    $file->update(['current_version_id' => $version->id]);

    FileText::create([
        'file_version_id' => $version->id,
        'status' => ExtractionStatus::Done,
        'text' => 'searchable words',
        'chars' => 16,
    ]);

    expect($file->fresh()->extractedText())->toBe('searchable words');
});

it('returns null when the current version has no text yet', function () {
    $version = FileVersion::factory()->create();
    $file = $version->file;
    $file->update(['current_version_id' => $version->id]);

    expect($file->fresh()->extractedText())->toBeNull();
});

it('goes away with its version', function () {
    $version = FileVersion::factory()->create();
    FileText::factory()->for($version, 'version')->create();

    $version->delete();

    expect(FileText::count())->toBe(0);
});
```

- [ ] **Step 2: Run and watch it fail**

- [ ] **Step 3: Write the migration**

```php
Schema::create('file_texts', function (Blueprint $table) {
    $table->id();
    $table->foreignId('file_version_id')->unique()->constrained()->cascadeOnDelete();
    $table->string('status', 16)->default('pending');
    $table->string('extractor', 32)->nullable();
    $table->longText('text')->nullable();
    $table->unsignedInteger('chars')->default(0);
    $table->text('error')->nullable();
    $table->timestamps();

    $table->index('status');
});
```

Kept off `files` deliberately: a directory listing must never drag megabytes of
OCR text along with it.

- [ ] **Step 4: Write the enum, model, factory and relations. Run focused, then full suite. Commit.**

---

### Task 3: Plain text and Office formats

**Files:**
- Create: `app/Extraction/TextExtractor.php`, `ExtractionResult.php`, `ExtractorChain.php`, `Strategies/PlainTextExtractor.php`, `Strategies/OfficeXmlExtractor.php`, `Strategies/UnsupportedExtractor.php`
- Test: `tests/Feature/Extraction/PlainTextExtractorTest.php`, `OfficeXmlExtractorTest.php`, `ExtractorChainTest.php`

**Interfaces:**
- `TextExtractor::supports(string $mime): bool`
- `TextExtractor::extract(string $path, string $mime): ExtractionResult`
- `TextExtractor::name(): string`
- `ExtractorChain::for(string $mime): TextExtractor`

Modern Office files are zip archives of XML, so they need no LibreOffice — which
is why `WITH_OFFICE` stays off by default in the image.

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

use App\Enums\ExtractionStatus;
use App\Extraction\Strategies\PlainTextExtractor;

it('reads a text file', function () {
    $path = tempnam(sys_get_temp_dir(), 'doccum');
    file_put_contents($path, "line one\nline two");

    $result = app(PlainTextExtractor::class)->extract($path, 'text/plain');

    expect($result->status)->toBe(ExtractionStatus::Done)
        ->and($result->text)->toContain('line one', 'line two')
        ->and($result->extractor)->toBe('plain');

    @unlink($path);
});

it('handles csv and markdown', function () {
    expect(app(PlainTextExtractor::class)->supports('text/plain'))->toBeTrue()
        ->and(app(PlainTextExtractor::class)->supports('text/csv'))->toBeTrue()
        ->and(app(PlainTextExtractor::class)->supports('text/markdown'))->toBeTrue()
        ->and(app(PlainTextExtractor::class)->supports('application/pdf'))->toBeFalse();
});

it('does not choke on invalid utf-8', function () {
    // Scanned exports and old exports routinely contain bad bytes; storing
    // them would break JSON encoding later, in the search index.
    $path = tempnam(sys_get_temp_dir(), 'doccum');
    file_put_contents($path, "valid \xB1\x31 text");

    $result = app(PlainTextExtractor::class)->extract($path, 'text/plain');

    expect(mb_check_encoding($result->text, 'UTF-8'))->toBeTrue()
        ->and($result->text)->toContain('valid');

    @unlink($path);
});
```

```php
<?php

declare(strict_types=1);

use App\Enums\ExtractionStatus;
use App\Extraction\Strategies\OfficeXmlExtractor;

/** Build a minimal but genuine .docx: a zip containing word/document.xml. */
function makeDocx(string $body): string
{
    $path = tempnam(sys_get_temp_dir(), 'doccum').'.docx';
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('word/document.xml',
        '<?xml version="1.0"?><w:document xmlns:w="x"><w:body><w:p><w:r><w:t>'
        .$body.'</w:t></w:r></w:p></w:body></w:document>');
    $zip->close();

    return $path;
}

it('extracts text from a docx', function () {
    $path = makeDocx('Quarterly report contents');

    $result = app(OfficeXmlExtractor::class)->extract($path, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

    expect($result->status)->toBe(ExtractionStatus::Done)
        ->and($result->text)->toContain('Quarterly report contents')
        ->and($result->text)->not->toContain('<w:t>');

    @unlink($path);
});

it('fails cleanly on a corrupt archive', function () {
    $path = tempnam(sys_get_temp_dir(), 'doccum').'.docx';
    file_put_contents($path, 'this is not a zip');

    $result = app(OfficeXmlExtractor::class)->extract($path, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

    expect($result->status)->toBe(ExtractionStatus::Failed)
        ->and($result->error)->not->toBeEmpty();

    @unlink($path);
});

it('claims the modern office types but not the legacy ones', function () {
    $e = app(OfficeXmlExtractor::class);

    expect($e->supports('application/vnd.openxmlformats-officedocument.wordprocessingml.document'))->toBeTrue()
        ->and($e->supports('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'))->toBeTrue()
        ->and($e->supports('application/msword'))->toBeFalse();
});
```

```php
<?php

declare(strict_types=1);

use App\Extraction\ExtractorChain;
use App\Extraction\Strategies\OfficeXmlExtractor;
use App\Extraction\Strategies\PlainTextExtractor;
use App\Extraction\Strategies\UnsupportedExtractor;

it('picks the right strategy for a mime type', function () {
    $chain = app(ExtractorChain::class);

    expect($chain->for('text/plain'))->toBeInstanceOf(PlainTextExtractor::class)
        ->and($chain->for('application/vnd.openxmlformats-officedocument.wordprocessingml.document'))
            ->toBeInstanceOf(OfficeXmlExtractor::class);
});

it('falls back to unsupported rather than failing', function () {
    // An unknown type is a document doccum cannot read yet, not an error.
    expect(app(ExtractorChain::class)->for('application/x-nintendo-rom'))
        ->toBeInstanceOf(UnsupportedExtractor::class);
});
```

- [ ] **Step 2: Run and watch them fail**

- [ ] **Step 3: Implement**

`ExtractionResult` is a readonly value object with named constructors
`done()`, `failed()`, `unsupported()`. The plain extractor coerces to valid
UTF-8 (`mb_convert_encoding` via `UTF-8, UTF-8` or `iconv //IGNORE`). The Office
extractor reads `word/document.xml`, `xl/sharedStrings.xml` or the slide XML by
type, strips tags, and collapses whitespace.

- [ ] **Step 4: Run focused, then full suite. Commit.**

---

### Task 4: PDFs, and deciding when to OCR

**Files:**
- Create: `app/Extraction/Strategies/PdfExtractor.php`, `Strategies/OcrExtractor.php`
- Modify: `config/doccum.php` (already has `extraction.scanned_pdf_threshold`, `ocr_page_limit`, `timeout_seconds`)
- Test: `tests/Feature/Extraction/PdfExtractorTest.php`, `OcrExtractorTest.php`

**Interfaces:**
- `PdfExtractor` — runs `pdftotext`; if the result is below the threshold, delegates to `OcrExtractor`
- `OcrExtractor` — `pdftoppm` to images then `tesseract` per page, capped at `ocr_page_limit`

The threshold is the whole point: a PDF with a text layer must never be OCR'd
(slow, and worse quality than the text already there), and a scan must never be
indexed as the empty string.

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

use App\Enums\ExtractionStatus;
use App\Extraction\Strategies\PdfExtractor;
use App\Support\ProcessRunner;

beforeEach(function () {
    $this->path = tempnam(sys_get_temp_dir(), 'doccum').'.pdf';
    file_put_contents($this->path, '%PDF-1.4 fake');
});

afterEach(fn () => @unlink($this->path));

it('uses the text layer when there is one', function () {
    ProcessRunner::fake(['pdftotext' => ['output' => str_repeat('real extracted text ', 20)]]);

    $result = app(PdfExtractor::class)->extract($this->path, 'application/pdf');

    expect($result->status)->toBe(ExtractionStatus::Done)
        ->and($result->extractor)->toBe('pdftotext')
        ->and($result->text)->toContain('real extracted text');

    app(ProcessRunner::class)->assertRan('pdftotext');
});

it('falls back to ocr when the text layer is too thin', function () {
    ProcessRunner::fake([
        'pdftotext' => ['output' => 'x'],           // a scan: a stray character
        'pdftoppm' => ['output' => ''],
        'tesseract' => ['output' => 'text recovered by ocr'],
    ]);

    $result = app(PdfExtractor::class)->extract($this->path, 'application/pdf');

    expect($result->extractor)->toBe('ocr')
        ->and($result->text)->toContain('text recovered by ocr');

    app(ProcessRunner::class)->assertRan('tesseract');
});

it('respects the configured threshold', function () {
    config()->set('doccum.extraction.scanned_pdf_threshold', 5);
    ProcessRunner::fake([
        'pdftotext' => ['output' => 'abcdefghij'],  // 10 chars, above 5
        'tesseract' => ['output' => 'should not be used'],
    ]);

    expect(app(PdfExtractor::class)->extract($this->path, 'application/pdf')->extractor)->toBe('pdftotext');
});

it('does not ocr a text pdf even when ocr is available', function () {
    // The expensive path must never run for a document that did not need it.
    ProcessRunner::fake([
        'pdftotext' => ['output' => str_repeat('plenty of text ', 30)],
        'tesseract' => ['output' => 'wasted work'],
    ]);

    app(PdfExtractor::class)->extract($this->path, 'application/pdf');

    expect(fn () => app(ProcessRunner::class)->assertRan('tesseract'))
        ->toThrow(PHPUnit\Framework\AssertionFailedError::class);
});

it('reports failure when pdftotext itself fails and ocr cannot help', function () {
    ProcessRunner::fake([
        'pdftotext' => ['exitCode' => 1, 'error' => 'damaged file'],
        'pdftoppm' => ['exitCode' => 1, 'error' => 'damaged file'],
    ]);

    $result = app(PdfExtractor::class)->extract($this->path, 'application/pdf');

    expect($result->status)->toBe(ExtractionStatus::Failed)
        ->and($result->error)->toContain('damaged');
});
```

```php
<?php

declare(strict_types=1);

use App\Enums\ExtractionStatus;
use App\Extraction\Strategies\OcrExtractor;
use App\Support\ProcessRunner;

it('caps how many pages it will ocr', function () {
    // One 400-page scan must not monopolise the ingest worker.
    config()->set('doccum.extraction.ocr_page_limit', 3);
    ProcessRunner::fake(['pdftoppm' => ['output' => ''], 'tesseract' => ['output' => 'page text']]);

    $path = tempnam(sys_get_temp_dir(), 'doccum').'.pdf';
    file_put_contents($path, '%PDF-1.4');

    $result = app(OcrExtractor::class)->extract($path, 'application/pdf');

    expect($result->status)->toBe(ExtractionStatus::Done)
        ->and($result->truncated)->toBeBool();

    @unlink($path);
});

it('ocrs an image directly without rasterising first', function () {
    ProcessRunner::fake(['tesseract' => ['output' => 'words in the photo']]);

    $path = tempnam(sys_get_temp_dir(), 'doccum').'.png';
    file_put_contents($path, 'not really a png');

    $result = app(OcrExtractor::class)->extract($path, 'image/png');

    expect($result->text)->toContain('words in the photo');
    expect(fn () => app(ProcessRunner::class)->assertRan('pdftoppm'))
        ->toThrow(PHPUnit\Framework\AssertionFailedError::class);

    @unlink($path);
});

it('reports unsupported rather than failing when tesseract is absent', function () {
    // An install without OCR should mark scans unsupported, not error on every
    // upload -- the difference between a missing feature and a broken one.
    ProcessRunner::fake([]);

    $path = tempnam(sys_get_temp_dir(), 'doccum').'.png';
    file_put_contents($path, 'x');

    expect(app(OcrExtractor::class)->extract($path, 'image/png')->status)
        ->toBeIn([ExtractionStatus::Unsupported, ExtractionStatus::Failed]);

    @unlink($path);
});
```

- [ ] **Step 2: Run and watch them fail**

- [ ] **Step 3: Implement both strategies**

`PdfExtractor` runs `pdftotext -layout <path> -`, counts non-whitespace
characters, and delegates to `OcrExtractor` below the threshold. `OcrExtractor`
rasterises PDFs with `pdftoppm -r 150 -png`, OCRs each produced page with
`tesseract <page> stdout`, stops at the page limit, sets `truncated`, and always
cleans up its temporary files — including on failure.

- [ ] **Step 4: Add one real-binary integration test, skipped when absent**

```php
it('extracts from a genuine pdf', function () {
    if (! app(ProcessRunner::class)->available('pdftotext')) {
        $this->markTestSkipped('pdftotext is not installed on this host');
    }
    // ...build a real one-page PDF and assert its text comes back.
})->group('integration');
```

The faked tests own the decision logic; this proves the command line is right.

- [ ] **Step 5: Run focused, then full suite. Commit.**

---

### Task 5: The job, and never being silently unsearchable

**Files:**
- Create: `app/Jobs/ExtractText.php`, `app/Console/Commands/ExtractRetry.php`
- Modify: `app/Actions/Files/StoreFileVersion.php`
- Test: `tests/Feature/ExtractTextJobTest.php`

**Interfaces:**
- `ExtractText` — queued on `ingest`, takes a `FileVersion`
- `doccum:extract:retry` — re-queues failed and pending extractions

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use App\Actions\Files\StoreFileVersion;
use App\Enums\ExtractionStatus;
use App\Jobs\ExtractText;
use App\Models\Directory;
use App\Models\FileText;
use App\Models\User;
use App\Support\ProcessRunner;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('documents');
    $this->user = User::factory()->create();
    $this->dir = Directory::factory()->create();
});

function storeText(string $name, string $contents, string $mime = 'text/plain')
{
    $path = tempnam(sys_get_temp_dir(), 'doccum');
    file_put_contents($path, $contents);

    return app(StoreFileVersion::class)->handle(test()->user, test()->dir, $path, $name, $mime);
}

it('is dispatched to the ingest queue on upload', function () {
    Queue::fake();

    storeText('a.txt', 'hello');

    Queue::assertPushedOn('ingest', ExtractText::class);
});

it('records extracted text against the version', function () {
    $file = storeText('a.txt', 'the quick brown fox');

    ExtractText::dispatchSync($file->currentVersion);

    $text = FileText::where('file_version_id', $file->currentVersion->id)->firstOrFail();

    expect($text->status)->toBe(ExtractionStatus::Done)
        ->and($text->text)->toContain('quick brown fox')
        ->and($text->chars)->toBeGreaterThan(0);
});

it('records a failure instead of leaving nothing behind', function () {
    ProcessRunner::fake(['pdftotext' => ['exitCode' => 1, 'error' => 'damaged']]);
    $file = storeText('a.pdf', '%PDF-1.4 broken', 'application/pdf');

    ExtractText::dispatchSync($file->currentVersion);

    // A version with no row at all is indistinguishable from one not yet
    // processed, so a failure must be written down.
    $text = FileText::where('file_version_id', $file->currentVersion->id)->firstOrFail();
    expect($text->status)->toBeIn([ExtractionStatus::Failed, ExtractionStatus::Unsupported]);
});

it('marks an unreadable type unsupported', function () {
    $file = storeText('a.rom', 'binary junk', 'application/x-nintendo-rom');

    ExtractText::dispatchSync($file->currentVersion);

    expect(FileText::first()->status)->toBe(ExtractionStatus::Unsupported);
});

it('is idempotent', function () {
    $file = storeText('a.txt', 'hello');

    ExtractText::dispatchSync($file->currentVersion);
    ExtractText::dispatchSync($file->currentVersion);

    expect(FileText::count())->toBe(1);
});

it('re-queues failures on demand', function () {
    Queue::fake();
    $file = storeText('a.txt', 'hello');
    FileText::create([
        'file_version_id' => $file->currentVersion->id,
        'status' => ExtractionStatus::Failed,
        'error' => 'transient',
    ]);

    $this->artisan('doccum:extract:retry')->assertSuccessful();

    Queue::assertPushed(ExtractText::class);
});

it('leaves a successful extraction alone when retrying', function () {
    Queue::fake();
    $file = storeText('a.txt', 'hello');
    FileText::create([
        'file_version_id' => $file->currentVersion->id,
        'status' => ExtractionStatus::Done,
        'text' => 'hello', 'chars' => 5,
    ]);

    $this->artisan('doccum:extract:retry')->assertSuccessful();

    Queue::assertNotPushed(ExtractText::class);
});
```

- [ ] **Step 2: Run and watch it fail**

- [ ] **Step 3: Implement**

The job downloads the object to a temporary path through `DocumentStorage`, runs
the chain, writes a `FileText` row via `updateOrCreate`, and deletes the
temporary file in a `finally`. Configure `$timeout` from
`doccum.extraction.timeout_seconds` and a small `$tries`; on final failure the
job's `failed()` hook records the `Failed` status so the outcome is visible even
when the worker gave up.

Dispatch from `StoreFileVersion` after the transaction commits — not inside it,
or the worker can pick the job up before the row it needs is visible.

- [ ] **Step 4: Run focused, then full suite. Commit.**

---

## Done when

- Uploading a text file, a docx or a PDF with a text layer makes its contents available on the file version, asynchronously.
- A PDF with no text layer is OCR'd; one with text is never OCR'd.
- OCR stops at the configured page limit and says it truncated.
- An install without tesseract marks scans unsupported rather than erroring on every upload.
- Every version ends with a `file_texts` row — done, unsupported or failed with a reason — so nothing is silently unsearchable.
- The whole suite passes on a machine with no OCR installed.
- `php artisan test` green; the container still boots clean from empty volumes.
