<?php

declare(strict_types=1);

use App\Domain\Privacy\Models\PrivacyRequest;
use App\Domain\Privacy\PersonalDataHolders;
use App\Domain\Privacy\PrivacyRequestsHolder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Teme\Contracts\Privacy\PersonalDataSubject;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->freezeSecond();

    // A request row as a run leaves it, $days days after it ended.
    $this->row = function (string $email, string $kind, int $days, bool $cleared = false, array $changes = []): string {
        $id = strtolower((string) Str::ulid());
        $results = $kind === 'erase'
            ? [['key' => 'comments', 'label' => 'Comments', 'page' => 1, 'done' => true, 'removed' => 2, 'retained' => 1, 'reasons' => ['Kept.']]]
            : [['key' => 'comments', 'label' => 'Comments', 'page' => 1, 'done' => true, 'found' => 2]];

        DB::table('privacy_requests')->insert([
            'id' => $id, 'kind' => $kind, 'email' => $cleared ? null : $email, 'email_hash' => PrivacyRequest::hashOf($email),
            'note' => 'Asked by email; checked from her address.', 'status' => 'done', 'results' => json_encode($results),
            'completed_at' => now()->subDays($days), 'created_at' => now()->subDays($days)->subMinute(), 'updated_at' => now()->subDays($days),
            ...$changes,
        ]);

        return $id;
    };
    $this->holder = app(PrivacyRequestsHolder::class);
    $this->ada = new PersonalDataSubject('ada@example.com', null);
});

it('exports the person’s own erasure requests, those whose address is hidden too, and nothing else', function (): void {
    ($this->row)('ada@example.com', 'erase', 2);
    ($this->row)('ada@example.com', 'erase', 40, cleared: true);
    ($this->row)('ada@example.com', 'export', 3);
    ($this->row)('grace@example.com', 'erase', 2);

    $page = $this->holder->export($this->ada, 1);

    expect($page->done)->toBeTrue()
        ->and($page->groups)->toHaveCount(2)
        ->and($page->groups[0]->title)->toBe('Erasure request')
        ->and($page->groups[0]->values)->toBe([
            'Recorded' => now()->subDays(40)->subMinute()->toIso8601String(),
            'Ended' => now()->subDays(40)->toIso8601String(),
            'Status' => 'Done',
            'Note' => 'Asked by email; checked from her address.',
            'Comments' => '2 removed, 1 kept.',
        ])
        ->and($page->groups[1]->values['Recorded'])->toBe(now()->subDays(2)->subMinute()->toIso8601String());
});

it('says why a stopped request stopped', function (): void {
    ($this->row)('ada@example.com', 'erase', 1, changes: ['status' => 'failed', 'failure' => 'RuntimeException: The holder broke.']);

    expect($this->holder->export($this->ada, 1)->groups[0]->values)
        ->toMatchArray(['Status' => 'Stopped', 'Why it stopped' => 'RuntimeException: The holder broke.']);
});

it('keeps the requests on erasure, with its reason, changing nothing', function (): void {
    ($this->row)('ada@example.com', 'erase', 2);
    ($this->row)('ada@example.com', 'erase', 40, cleared: true);
    ($this->row)('grace@example.com', 'erase', 2);
    $before = DB::table('privacy_requests')->orderBy('id')->get();

    $answer = $this->holder->erase($this->ada, 1);

    expect($answer->removed)->toBe(0)
        ->and($answer->retained)->toBe(2)
        ->and($answer->reasons)->toBe(['The record of each request is kept for a year, its address hidden after 30 days.'])
        ->and($answer->done)->toBeTrue()
        ->and(DB::table('privacy_requests')->orderBy('id')->get())->toEqual($before);
});

it('is the last of core’s holders', function (): void {
    expect(PersonalDataHolders::CORE[array_key_last(PersonalDataHolders::CORE)])->toBe(PrivacyRequestsHolder::class)
        ->and($this->holder->key())->toBe('privacy-requests')
        ->and($this->holder->label())->toBe('Privacy requests');
});
