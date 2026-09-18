<?php

declare(strict_types=1);

use App\Actions\Files\TrashFile;
use App\Actions\Users\CreateHomeDirectory;
use App\Enums\AccessLevel;
use App\Livewire\Home\Index as Home;
use App\Models\Directory;
use App\Models\DirectoryGrant;
use App\Models\File;
use App\Models\User;
use Livewire\Livewire;

/**
 * item/home-dashboard (issue #16): the Home destination spec §10 names --
 * "recent files, recent activity, storage consumed by period, quick search
 * entry" -- narrowed to what this item's doneWhen asks for: recents filtered
 * by DirectoryAccess, and a page that renders correctly at zero data.
 *
 * The isolation tests below assert against App\Livewire\Home\Index::render()'s
 * own $recentFiles view data first -- the QUERY-level assertion the doneWhen
 * names -- and only then also check the rendered HTML, the same
 * belt-and-suspenders shape tests/Feature/TrashViewTest.php already uses for
 * the same kind of claim.
 */
function grantHomeAccess(Directory $directory, User $user, AccessLevel $level): void
{
    DirectoryGrant::create([
        'directory_id' => $directory->id,
        'grantee_type' => 'user',
        'grantee_id' => $user->id,
        'level' => $level,
    ]);
}

// --- zero data (the doneWhen's own clause) ---------------------------------

it('renders for a brand-new user with no reachable directories at all, at zero data', function () {
    // Deliberately NOT app(CreateHomeDirectory::class)->handle($user) --
    // this is the case the doneWhen names explicitly: a viewer whose
    // DirectoryAccess::viewableDirectoryIds() comes back [], which is what
    // a freshly registered user's reach genuinely is until a home directory
    // grant exists. See CreateHomeDirectory's own docblock: it is called
    // from registration/first-run, never implicitly by User::factory().
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test(Home::class)
        ->assertOk();

    expect($component->viewData('recentFiles'))->toBeEmpty();

    $component->assertSee(__('No recent files yet.'));
});

it('reaches the dashboard route over HTTP for a brand-new user without crashing', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
});

// --- isolation (issue #16's own doneWhen: filtered by DirectoryAccess) ------

it('never lists another user\'s file in recents, asserted against the query result itself', function () {
    $mine = Directory::factory()->create(['name' => 'Mine']);
    $theirs = Directory::factory()->create(['name' => 'Theirs']);

    $user = User::factory()->create();
    grantHomeAccess($mine, $user, AccessLevel::View);

    $stranger = User::factory()->create();
    grantHomeAccess($theirs, $stranger, AccessLevel::View);

    $mineFile = File::factory()->for($mine, 'directory')->create(['name' => 'mine.txt']);
    $theirsFile = File::factory()->for($theirs, 'directory')->create(['name' => 'theirs.txt']);

    $component = Livewire::actingAs($user)->test(Home::class);

    // The QUERY-level assertion the doneWhen names: render()'s own
    // $recentFiles never contains $theirsFile, regardless of what the view
    // does with it.
    expect($component->viewData('recentFiles')->pluck('id')->all())
        ->toBe([$mineFile->id]);

    $component->assertSee('mine.txt')->assertDontSee('theirs.txt');
});

it('lists nothing when the viewer holds no grant on the file\'s directory at all', function () {
    $theirs = Directory::factory()->create();
    File::factory()->for($theirs, 'directory')->create(['name' => 'theirs.txt']);

    // No grant on anything -- $viewable is [], the exact zero-reach shape
    // the doneWhen's "zero data" clause is about, but reached here via a
    // stranger's file existing rather than via no files existing at all.
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test(Home::class)
        ->assertOk()
        ->assertDontSee('theirs.txt');

    expect($component->viewData('recentFiles'))->toBeEmpty();
});

// --- trashed files must not appear (hard constraint) ------------------------

it('excludes a trashed file from recents even though its directory is still reachable', function () {
    $mine = Directory::factory()->create(['name' => 'Mine']);
    $user = User::factory()->create();
    grantHomeAccess($mine, $user, AccessLevel::View);

    $file = File::factory()->for($mine, 'directory')->create(['name' => 'trashed.txt']);
    app(TrashFile::class)->handle($file);

    $component = Livewire::actingAs($user)->test(Home::class);

    expect($component->viewData('recentFiles')->pluck('id')->all())->toBe([]);
    $component->assertDontSee('trashed.txt');
});

it('lists a live file in the same directory as an unrelated trashed one', function () {
    $mine = Directory::factory()->create(['name' => 'Mine']);
    $user = User::factory()->create();
    grantHomeAccess($mine, $user, AccessLevel::View);

    $live = File::factory()->for($mine, 'directory')->create(['name' => 'live.txt']);
    $trashed = File::factory()->for($mine, 'directory')->create(['name' => 'gone.txt']);
    app(TrashFile::class)->handle($trashed);

    $component = Livewire::actingAs($user)->test(Home::class);

    expect($component->viewData('recentFiles')->pluck('id')->all())->toBe([$live->id]);
    $component->assertSee('live.txt')->assertDontSee('gone.txt');
});

// --- the Home/Home accessible-name collision (spec §10, issue #140) --------

/**
 * The smallest correct approximation of accessible-name computation this
 * needs: for a link, aria-label wins outright when present (and is used
 * verbatim, never merged with the text content); otherwise the accessible
 * name is the element's own trimmed, whitespace-collapsed text content.
 * Good enough for a same-document comparison of doccum's own markup -- this
 * is not a general WAI-ARIA accessible-name engine.
 *
 * @return array<int, string>
 */
function accessibleNamesOfLinks(string $html): array
{
    $previousErrorState = libxml_use_internal_errors(true);
    $document = new DOMDocument;
    $document->loadHTML($html);
    libxml_use_internal_errors($previousErrorState);

    $names = [];

    foreach ((new DOMXPath($document))->query('//a') as $node) {
        /** @var DOMElement $node */
        $ariaLabel = $node->getAttribute('aria-label');

        $names[] = trim($ariaLabel !== '' ? $ariaLabel : preg_replace('/\s+/', ' ', $node->textContent));
    }

    return $names;
}

/**
 * THE doneWhen clause this item is named for, on the /files page rather than
 * /dashboard -- spec §10 names BOTH the topbar entry and the Files sidebar's
 * pinned entry "Home", and that collision belongs to the spec, not to
 * item/files-tree-sidebar (issue #140). Exactly one of the two links on
 * /files may have the accessible name "Home" -- the topbar's, unchanged;
 * the sidebar's keeps its VISIBLE text "Home" (per spec) but carries an
 * accessible name beginning with it ("Home directory"), which is
 * WCAG "label in name" and is why the fix is that string rather than the
 * username (the tree's own first entry already reads that) or "My files"
 * (a term spec §10 never uses).
 *
 * This is written to be shown FAILING against main first -- both links read
 * "Home" there, with no aria-label on either -- and the repo owner runs
 * that, per this item's own instructions: this session cannot run the
 * suite at all (no vendor/, egress blocked).
 */
it('has exactly one link on /files with the accessible name "Home"', function () {
    $user = User::factory()->create(['username' => 'ada']);
    app(CreateHomeDirectory::class)->handle($user);

    $response = $this->actingAs($user)->get(route('files.browse'));
    $response->assertOk();

    $names = accessibleNamesOfLinks($response->getContent());
    $matches = array_values(array_filter($names, static fn (string $name): bool => $name === __('Home')));

    expect($matches)->toHaveCount(1);
});

/**
 * The other half of the same fix, stated as its own assertion rather than
 * folded into the count above: the sidebar's entry keeps "Home" as its
 * VISIBLE text (spec §10's own wording), even though its accessible name is
 * now something else.
 */
it('keeps the sidebar\'s pinned entry\'s visible text as "Home"', function () {
    $user = User::factory()->create(['username' => 'ada']);
    app(CreateHomeDirectory::class)->handle($user);

    $this->actingAs($user)
        ->get(route('files.browse'))
        ->assertSee(__('Home'))
        ->assertSee('aria-label="'.__('Home directory').'"', false);
});
