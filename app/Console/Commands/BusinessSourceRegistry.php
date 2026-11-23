<?php

namespace App\Console\Commands;

use App\Support\BusinessImports\BusinessImportOperator;
use App\Support\BusinessImports\BusinessSources;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;

class BusinessSourceRegistry extends Command
{
    protected $signature = 'businesses:sources {action : list, register, show, revise, approve, or suspend}
        {id?} {--actor=} {--name=} {--url=} {--license=} {--terms=} {--vintage=} {--revision=} {--reason=}';

    protected $description = 'Manage operator-reviewed reuse rights for business CSV sources';

    public function handle(BusinessSources $registry): int
    {
        try {
            $actor = BusinessImportOperator::authenticate($this);
            $action = $this->argument('action');
            if ($action === 'list') {
                $sources = DB::table('business_sources')->orderBy('id')->limit(100)->get();
                $this->table(['ID', 'Source', 'Revision', 'Approved revision', 'Data vintage'], $sources->map(fn ($source) => [
                    $source->id, OutputFormatter::escape($source->name), $source->revision, $source->approved_revision ?? 'Unapproved', $source->data_as_of,
                ])->all());

                return self::SUCCESS;
            }
            if ($action === 'register') {
                $id = $registry->register($actor, (string) $this->option('name'), (string) $this->option('url'),
                    (string) $this->option('license'), $this->option('terms'), $this->option('vintage'));
                $this->info("Source $id is available for preview. Check its approval with businesses:sources show.");

                return self::SUCCESS;
            }

            $id = BusinessImportOperator::id($this->argument('id'));
            if ($action === 'show') {
                $source = $registry->get($id);
                $fields = array_intersect_key((array) $source, array_flip(['name', 'url', 'license', 'terms_url', 'data_as_of', 'revision', 'approved_revision']));
                $this->line(OutputFormatter::escape(json_encode($fields, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)));

                return self::SUCCESS;
            }

            $revision = filter_var($this->option('revision'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($this->option('revision') === null || $revision === false) {
                throw new RuntimeException('Enter the current source --revision.');
            }
            $reason = fn () => (string) ($this->option('reason') ?? $this->ask('Review reference or reason'));
            match ($action) {
                'revise' => $registry->revise($actor, $id, $revision, (string) $this->option('url'), (string) $this->option('license'),
                    $this->option('terms'), $this->option('vintage')),
                'approve' => $registry->approve($actor, $id, $revision, $reason()),
                'suspend' => $registry->suspend($actor, $id, $revision, $reason()),
                default => throw new RuntimeException('Unknown source registry operation.'),
            };
            $this->info("Source $id was updated.");

            return self::SUCCESS;
        } catch (QueryException) {
            $this->error('The source registry write failed and was rolled back.');

            return self::FAILURE;
        } catch (AuthorizationException|ValidationException|RuntimeException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
