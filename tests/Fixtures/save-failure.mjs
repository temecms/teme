// Prints what the editor makes of each way an autosave can fail (issue 6),
// for SaveFailureTest: saveFailure() for each answer, and retryDelay() for
// each delay. Its argument is a JSON file of { answers: [[status, reason]],
// delays: [[backoffMs, retryAfter, nowMs]] }, status null for no answer at all.
import './editor-hooks.mjs';
import { readFileSync } from 'node:fs';

const { saveFailure, retryDelay } = await import('../../resources/js/components/editor/save-failure.ts');

const input = JSON.parse(readFileSync(process.argv[2], 'utf8'));

const failures = input.answers.map(([status, reason]) => saveFailure(status, reason));

process.stdout.write(
    JSON.stringify(
        input.delays === undefined
            ? failures
            : { failures, delays: input.delays.map(([backoff, header, now]) => retryDelay(backoff, header, now)) },
    ),
);
