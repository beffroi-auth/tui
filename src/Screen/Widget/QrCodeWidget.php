<?php

declare(strict_types=1);

namespace Beffroi\Tui\Screen\Widget;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\ByteMatrix;
use BaconQrCode\Encoder\Encoder;
use Closure;
use function intdiv;
use function str_repeat;
use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\FocusableInterface;
use Symfony\Component\Tui\Widget\FocusableTrait;
use Symfony\Component\Tui\Widget\KeybindingsTrait;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

/**
 * A QR code of a text (ISO/IEC 18004), two modules to a line drawn in half blocks, dark on light whatever the terminal
 * draws around it, inside a quiet zone of the four modules the standard asks for, or of two when the terminal cannot
 * spare four. A code the terminal cannot hold whole is never drawn cut: the widget says the room it needs instead.
 *
 * It takes the focus, so that the keys a section answers while the code is shown reach it, and it tells the section
 * when the focus leaves it.
 */
final class QrCodeWidget extends AbstractWidget implements FocusableInterface, VerticallyExpandableInterface, SectionKeysInterface
{
    use ExpandsVerticallyTrait;
    use FocusableTrait {
        setFocused as private focus;
    }
    use KeybindingsTrait;

    private const int QUIET = 4;

    private const int TIGHT = 2;

    private ?ByteMatrix $matrix = null;

    private string $tooSmall = '';

    /**
     * @var list<array{string, string}>
     */
    private array $sectionKeys = [];

    /**
     * @var (Closure(): void)|null
     */
    private ?Closure $left = null;

    /**
     * @var (Closure(): void)|null
     */
    private ?Closure $closed = null;

    public function setText(string $text): static
    {
        $this->matrix = Encoder::encode($text, ErrorCorrectionLevel::M(), Encoder::DEFAULT_BYTE_MODE_ENCODING, null, false)->getMatrix();
        $this->invalidate();

        return $this;
    }

    public function forget(): static
    {
        $this->matrix = null;
        $this->invalidate();

        return $this;
    }

    /**
     * The columns and the lines the code takes inside the narrowest quiet zone, nothing when no text was given.
     *
     * @return array{int, int}
     */
    public function room(): array
    {
        if ($this->matrix === null) {
            return [0, 0];
        }
        $modules = $this->matrix->getWidth() + (2 * self::TIGHT);

        return [$modules, intdiv($modules + 1, 2)];
    }

    /**
     * @param string $tooSmall what the widget says in place of a code the terminal cannot hold
     */
    public function setTooSmall(string $tooSmall): static
    {
        $this->tooSmall = $tooSmall;
        $this->invalidate();

        return $this;
    }

    /**
     * @param list<array{string, string}> $keys each key as it is written, and what it does
     */
    public function setSectionKeys(array $keys): static
    {
        $this->sectionKeys = $keys;

        return $this;
    }

    public function sectionKeys(): array
    {
        return $this->sectionKeys;
    }

    /**
     * @param Closure(): void $callback told when the focus leaves the code, whatever key moved it
     */
    public function onLeave(Closure $callback): static
    {
        $this->left = $callback;

        return $this;
    }

    /**
     * @param Closure(): void $callback told when Enter closes the code
     */
    public function onClose(Closure $callback): static
    {
        $this->closed = $callback;

        return $this;
    }

    public function setFocused(bool $focused): static
    {
        $leaving = $this->isFocused() && ! $focused;
        $this->focus($focused);
        if ($leaving && $this->left !== null) {
            ($this->left)();
        }

        return $this;
    }

    public function handleInput(string $data): void
    {
        if ($this->onInput !== null && ($this->onInput)($data)) {
            return;
        }
        if ($this->closed !== null && $this->getKeybindings()->matches($data, 'close')) {
            ($this->closed)();
        }
    }

    /**
     * @return list<string>
     */
    public function render(RenderContext $context): array
    {
        $matrix = $this->matrix;
        if ($matrix === null) {
            return [];
        }
        $width = $context->getColumns();
        foreach ([self::QUIET, self::TIGHT] as $quiet) {
            $modules = $matrix->getWidth() + (2 * $quiet);
            if ($modules <= $width && intdiv($modules + 1, 2) <= $context->getRows()) {
                return $this->drawn($matrix, $quiet, $width);
            }
        }

        return [$this->applyElement('small', Fit::line($this->tooSmall, $width))];
    }

    /**
     * @return array<string, list<string>>
     */
    protected static function getDefaultKeybindings(): array
    {
        return [
            'close' => [Key::ENTER],
        ];
    }

    /**
     * Each line holds two rows of modules: the upper half block draws the first, the lower half the second.
     *
     * @return list<string>
     */
    private function drawn(ByteMatrix $matrix, int $quiet, int $width): array
    {
        $modules = $matrix->getWidth() + (2 * $quiet);
        $margin = str_repeat(' ', intdiv($width - $modules, 2));
        $lines = [];
        for ($row = 0; $row < $modules; $row += 2) {
            $line = '';
            for ($column = 0; $column < $modules; ++$column) {
                $upper = self::dark($matrix, $column - $quiet, $row - $quiet);
                $lower = self::dark($matrix, $column - $quiet, $row + 1 - $quiet);
                $line .= match (true) {
                    $upper && $lower => '█',
                    $upper => '▀',
                    $lower => '▄',
                    default => ' ',
                };
            }
            $lines[] = $margin . $this->applyElement('code', $line);
        }

        return $lines;
    }

    private static function dark(ByteMatrix $matrix, int $column, int $row): bool
    {
        $size = $matrix->getWidth();

        return $column >= 0 && $row >= 0 && $column < $size && $row < $size && $matrix->get($column, $row) === 1;
    }
}
