<?php

namespace ProcessWire;

require_once dirname(__DIR__) . '/src/Features/Context/src/JigsawContextSampleSerializer.php';

final class FakeSubfield {
    public function __construct(public string $name) {}
}

final class FakeComboValue {
    public function __construct(private array $values) {}

    public function getSubfields(): array {
        return array_map(
            static fn(string $name): FakeSubfield => new FakeSubfield($name),
            array_keys($this->values)
        );
    }

    public function get(string $name) {
        return $this->values[$name] ?? null;
    }

    public function jsonSerialize(): array {
        return [];
    }
}

$reflection = new \ReflectionClass(JigsawContextSampleSerializer::class);
$serializer = $reflection->newInstanceWithoutConstructor();
$normalize = $reflection->getMethod('normalizeStructuredValue');

$value = new FakeComboValue([
    'type' => 'products',
    'query' => 'template=product, country=123',
    'nested' => new FakeComboValue(['limit' => 12]),
]);
$actual = $normalize->invoke($serializer, $value);
$expected = [
    'type' => 'products',
    'query' => 'template=product, country=123',
    'nested' => ['limit' => 12],
];

if($actual !== $expected) {
    fwrite(STDERR, "Structured normalization failed\n");
    fwrite(STDERR, var_export($actual, true) . "\n");
    exit(1);
}

echo "Context sample serializer: OK\n";
