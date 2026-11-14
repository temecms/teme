<?php

declare(strict_types=1);

namespace Teme\Contracts\Hooks;

/**
 * Where a listener runs among a hook's listeners: a stage, and the plugins
 * it runs after or before inside that stage, by their Composer package
 * names. Never a number. Each method gives a copy.
 *
 * @since 0.5.0
 */
final readonly class Order
{
    /**
     * @param  list<string>  $after  plugins this listener runs after
     * @param  list<string>  $before  plugins this listener runs before
     */
    private function __construct(
        public Stage $stage,
        public array $after = [],
        public array $before = [],
    ) {}

    /** @since 0.5.0 */
    public static function early(): self
    {
        return new self(Stage::Early);
    }

    /** The order a listener has when it is given none. @since 0.5.0 */
    public static function normal(): self
    {
        return new self(Stage::Normal);
    }

    /** @since 0.5.0 */
    public static function late(): self
    {
        return new self(Stage::Late);
    }

    /** A copy that also runs after these plugins' listeners. @since 0.5.0 */
    public function after(string ...$plugins): self
    {
        return new self($this->stage, [...$this->after, ...array_values($plugins)], $this->before);
    }

    /** A copy that also runs before these plugins' listeners. @since 0.5.0 */
    public function before(string ...$plugins): self
    {
        return new self($this->stage, $this->after, [...$this->before, ...array_values($plugins)]);
    }
}
