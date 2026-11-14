<?php

declare(strict_types=1);

use App\Domain\Deployment\BreaksPreviousRelease;

/**
 * The migrations in $directory dated 2027_03_01 or later that remove or rename a column or a table in the body
 * of up() and don't implement BreaksPreviousRelease, by file name. Roll back by image is safe only when no
 * migration since contracts (I-377). It finds plain cases: Schema calls and SQL written out in up(); SQL built
 * at run time, and a drop made through another class, are not seen.
 *
 * @return list<string>
 */
function expandThenContractOffenders(string $directory): array
{
    $offenders = [];

    foreach (glob("{$directory}/*.php") ?: [] as $file) {
        $name = basename($file);

        if ($name < '2027_03_01') {
            continue;
        }

        $source = (string) file_get_contents($file);

        if (preg_match('/new\s+class\b[^{]*\b'.preg_quote('BreaksPreviousRelease', '/').'\b/', $source) === 1) {
            continue;
        }

        $body = upBodyOf($source);

        $drops = [
            '/->\s*dropColumns?\s*\(/', '/->\s*renameColumn\s*\(/',
            '/Schema::\s*(drop|dropIfExists|rename)\s*\(/',
            '/ALTER\s+TABLE\s+\S+\s+(DROP\s+COLUMN|RENAME\s+(COLUMN|TO))|DROP\s+TABLE/i',
        ];

        foreach ($drops as $drop) {
            if (preg_match($drop, $body) === 1) {
                $offenders[] = $name;

                break;
            }
        }
    }

    return $offenders;
}

/** The text of the body of the up() method, found with the tokenizer, or an empty string. */
function upBodyOf(string $source): string
{
    $tokens = token_get_all($source);
    $count = count($tokens);

    for ($at = 0; $at < $count; $at++) {
        if (! is_array($tokens[$at]) || $tokens[$at][0] !== T_FUNCTION) {
            continue;
        }

        $after = $at + 1;

        while ($after < $count && is_array($tokens[$after]) && $tokens[$after][0] === T_WHITESPACE) {
            $after++;
        }

        if (! is_array($tokens[$after] ?? null) || $tokens[$after][0] !== T_STRING || $tokens[$after][1] !== 'up') {
            continue;
        }

        $depth = 0;
        $body = '';
        $opened = false;

        for ($index = $after; $index < $count; $index++) {
            $token = $tokens[$index];
            $text = is_array($token) ? $token[1] : $token;

            if ($text === '{' || (is_array($token) && $token[0] === T_CURLY_OPEN) || (is_array($token) && $token[0] === T_DOLLAR_OPEN_CURLY_BRACES)) {
                $depth++;
                $opened = true;
            } elseif ($text === '}') {
                $depth--;
            }

            if ($opened) {
                $body .= $text;
            }

            if ($opened && $depth === 0) {
                return $body;
            }
        }
    }

    return '';
}

it('has every migration since releases began that drops or renames a column or a table in up() carry BreaksPreviousRelease', function (): void {
    expect(expandThenContractOffenders(dirname(__DIR__, 2).'/database/migrations'))->toBe([]);
});

it('sees a drop or a rename in up(), and leaves a down() alone, and a migration that says it breaks the release before', function (): void {
    $directory = sys_get_temp_dir().'/teme-expand-contract-'.bin2hex(random_bytes(4));
    mkdir($directory);
    $migration = fn (string $up, string $down = '', string $class = 'extends Migration'): string => "<?php\nreturn new class {$class}\n{\n    public function up(): void\n    {\n        {$up}\n    }\n\n    public function down(): void\n    {\n        {$down}\n    }\n};\n";
    $files = [
        '2027_03_02_900001_drops_a_column.php' => $migration('Schema::table(\'a\', fn ($t) => $t->dropColumn(\'b\'));'),
        '2027_03_02_900002_renames_a_column.php' => $migration('Schema::table(\'a\', function ($t) { $t->renameColumn(\'b\', \'c\'); });'),
        '2027_03_02_900003_drops_a_table.php' => $migration('Schema::dropIfExists(\'a\');'),
        '2027_03_02_900004_renames_a_table.php' => $migration('Schema::rename(\'a\', \'b\');'),
        '2027_03_02_900005_drops_in_sql.php' => $migration('DB::statement("ALTER TABLE a DROP COLUMN b");'),
        '2027_03_02_900006_renames_in_sql.php' => $migration('DB::statement("alter table a rename to b");'),
        '2027_03_02_900007_drops_only_in_down.php' => $migration('Schema::create(\'a\', fn ($t) => $t->id());', 'Schema::dropIfExists(\'a\');'),
        '2027_03_02_900008_renames_an_index.php' => $migration('DB::statement("ALTER INDEX a RENAME TO b");'),
        '2027_03_02_900009_says_it_breaks.php' => $migration('Schema::dropIfExists(\'a\');', '', 'extends Migration implements \\'.BreaksPreviousRelease::class),
        '2027_02_28_900010_before_releases.php' => $migration('Schema::dropIfExists(\'a\');'),
    ];

    foreach ($files as $name => $contents) {
        file_put_contents("{$directory}/{$name}", $contents);
    }

    $offenders = expandThenContractOffenders($directory);

    foreach (array_keys($files) as $name) {
        unlink("{$directory}/{$name}");
    }

    rmdir($directory);

    expect($offenders)->toBe([
        '2027_03_02_900001_drops_a_column.php', '2027_03_02_900002_renames_a_column.php', '2027_03_02_900003_drops_a_table.php',
        '2027_03_02_900004_renames_a_table.php', '2027_03_02_900005_drops_in_sql.php', '2027_03_02_900006_renames_in_sql.php',
    ]);
});
