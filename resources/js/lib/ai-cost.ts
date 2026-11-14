type Tokens = {
    input: number;
    output: number;
    cacheRead: number;
    cacheWrite: number;
};

/**
 * US dollars from whole micro-dollars (I-1303): four places under one cent,
 * so a $0.0014 request isn't "$0.00", and two otherwise.
 */
export function formatCost(microunits: number): string {
    const places = microunits > 0 && microunits < 10_000 ? 4 : 2;

    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: places,
        maximumFractionDigits: places,
    }).format(microunits / 1_000_000);
}

/** Every token a request used, of the four kinds. */
export function tokensUsed(tokens: Tokens): number {
    return tokens.input + tokens.output + tokens.cacheRead + tokens.cacheWrite;
}

/** "No tokens", "1 token", "1,280 tokens". */
export function tokenWords(count: number): string {
    if (count === 0) {
        return 'No tokens';
    }

    return count === 1 ? '1 token' : `${count.toLocaleString()} tokens`;
}

/** "Under a second", "1 second", "2.3 seconds". */
export function secondsWords(milliseconds: number): string {
    if (milliseconds < 950) {
        return 'Under a second';
    }

    const seconds = Math.round(milliseconds / 100) / 10;

    return seconds === 1 ? '1 second' : `${seconds} seconds`;
}

/**
 * An instant as the site's clock shows it, with its year, since the record
 * spans a year: "12 Nov 2026, 14:03".
 */
export function whenWords(iso: string, zone: string): string {
    return new Intl.DateTimeFormat([], {
        timeZone: zone,
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(iso));
}

/**
 * A request's cost in words: the dollars; "Unknown" when its model has no
 * price (I-1302); "None" when it used no tokens. `known` is false for the
 * last two, which are drawn muted.
 */
export function costWords(
    costMicrounits: number | null,
    tokens: Tokens,
): { text: string; known: boolean } {
    if (costMicrounits === null) {
        return { text: 'Unknown', known: false };
    }

    return tokensUsed(tokens) === 0
        ? { text: 'None', known: false }
        : { text: formatCost(costMicrounits), known: true };
}
