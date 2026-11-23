<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\BusinessImports\BusinessImportOperator;
use App\Support\BusinessImports\BusinessImportPlan;
use App\Support\BusinessImports\BusinessSources;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ImportBusinesses extends Command
{
    protected $signature = 'businesses:import {file?} {--source=} {--license=} {--url=} {--source-id=} {--actor=}
        {--full-snapshot} {--review=} {--apply=} {--resolve=} {--token=} {--line=} {--action=} {--business=} {--reason=}
        {--decision=} {--export} {--purge}';

    protected $description = 'Preview, resolve and apply a reviewed business CSV import';

    public function handle(BusinessImportPlan $import, BusinessSources $sources): int
    {
        try {
            $actor = BusinessImportOperator::authenticate($this);
            $operations = array_filter([
                $this->argument('file'), $this->option('review'), $this->option('resolve'), $this->option('apply'), $this->option('purge'),
            ], fn ($value) => $value !== null && $value !== false);
            if (count($operations) !== 1) {
                throw new RuntimeException('Select one CSV preview, --review, --resolve, --apply or --purge operation.');
            }
            if ($this->option('apply') !== null) {
                $counts = $import->apply($actor, BusinessImportOperator::id($this->option('apply')), $this->token(), $this->reason());
                $this->info('Applied import. '.json_encode($counts, JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }
            if ($this->option('purge')) {
                $this->info('Expired private payloads purged: '.$import->purge($actor));

                return self::SUCCESS;
            }
            if ($this->option('resolve') !== null) {
                $id = BusinessImportOperator::id($this->option('resolve'));
                $import->resolve($actor, $id, $this->token(), BusinessImportOperator::id($this->option('line')),
                    (string) $this->option('action'),
                    $this->option('business') === null ? null : BusinessImportOperator::id($this->option('business')), $this->reason());
            } elseif ($this->option('review') !== null) {
                $id = BusinessImportOperator::id($this->option('review'));
            } else {
                $id = $this->preview($import, $sources, $actor);
            }
            $this->review($import, $actor, $id);

            return self::SUCCESS;
        } catch (QueryException) {
            $this->error('The database operation failed. The import transaction was rolled back.');

            return self::FAILURE;
        } catch (AuthorizationException|ValidationException|RuntimeException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }

    private function preview(BusinessImportPlan $import, BusinessSources $sources, User $actor): int
    {
        $file = $this->argument('file');
        $path = realpath($file);
        if ($path === false || ! is_file($path) || ! is_readable($path) || ! str_ends_with(strtolower($file), '.csv')
            || preg_match('~(?:^|[/\\\\])\.\.(?:[/\\\\]|$)~', $file)) {
            throw new RuntimeException('Select a readable, local .csv file without parent-path segments.');
        }
        $source = $this->option('source-id') !== null ? BusinessImportOperator::id($this->option('source-id'))
            : $sources->register($actor, (string) $this->option('source'), (string) $this->option('url'), (string) $this->option('license'), null, null);
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('The CSV could not be opened.');
        }
        try {
            return $import->stage($actor, $source, $stream, $this->option('full-snapshot'))->id;
        } finally {
            fclose($stream);
        }
    }

    private function review(BusinessImportPlan $import, User $actor, int $id): void
    {
        $plan = $import->inspect($actor, $id);
        $rows = $import->listRows($actor, $id, $this->option('decision'));
        $headings = ['Line', 'Decision', 'Why', 'Candidate IDs', 'Business ID', 'Branch ID'];
        if ($this->option('export')) {
            $output = fopen('php://output', 'wb');
            fputcsv($output, $headings, escape: '');
            foreach ($rows as $row) {
                fputcsv($output, array_map(self::safeCell(...), $this->reviewRow($row)), escape: '');
            }
            fclose($output);

            return;
        }

        $this->line("Plan $id: $plan->status. Source $plan->source_id revision $plan->source_revision. Expires $plan->expires_at.");
        $this->line("Input SHA-256 $plan->input_sha256");
        $this->line("Review token $plan->review_token");
        $this->line(json_encode($import->counts($actor, $id), JSON_THROW_ON_ERROR));
        $table = [];
        foreach ($rows as $row) {
            $table[] = $this->reviewRow($row);
            if (count($table) === 25) {
                break;
            }
        }
        $this->table($headings, $table);
        $this->comment('Use --review with --decision or --export to inspect the complete plan.');
    }

    private function reviewRow(object $row): array
    {
        $details = json_decode($row->details, true, flags: JSON_THROW_ON_ERROR);

        return [$row->row_number, $row->decision, $details['reason'] ?? '',
            implode(', ', $details['candidates'] ?? []),
            $row->result_business_id ?? $details['business'] ?? null, $row->result_branch_id ?? $details['branch'] ?? null];
    }

    private static function safeCell(mixed $value): string
    {
        $text = (string) $value;

        return preg_match('/\A\s*[=+\-@]/', $text) ? "'".$text : $text;
    }

    private function token(): string
    {
        $token = (string) $this->option('token');
        if (! preg_match('/\A[a-f0-9]{64}\z/', $token)) {
            throw new RuntimeException('Enter the exact --token shown on the current review plan.');
        }

        return $token;
    }

    private function reason(): string
    {
        return (string) ($this->option('reason') ?? $this->ask('Review reference or reason'));
    }
}
