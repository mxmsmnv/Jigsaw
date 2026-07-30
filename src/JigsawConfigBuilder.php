<?php namespace ProcessWire;

final class JigsawConfigBuilder {

	public static function build(array $data): InputfieldWrapper {
		$modules = wire('modules');
		$root = wire(new InputfieldWrapper());
		$featureRoot = __DIR__ . '/Features/';

		foreach (JigsawFeatureRegistry::definitions() as $key => $definition) {
			/** @var InputfieldFieldset $section */
			$section = $modules->get('InputfieldFieldset');
			$section->label = $definition['title'];
			$section->description = $definition['description'];
			$section->icon = self::iconFor($key);
			$section->collapsed = Inputfield::collapsedYes;

			/** @var InputfieldCheckbox $enabled */
			$enabled = $modules->get('InputfieldCheckbox');
			$enabled->attr('name', "feature_{$key}_enabled");
			$enabled->label = 'Enable feature';
			$enabled->attr('value', 1);
			$enabled->attr('checked', array_key_exists("feature_{$key}_enabled", $data)
				? (bool)$data["feature_{$key}_enabled"]
				: true);
			$section->add($enabled);

			if (!empty($definition['config'])) {
				require_once $featureRoot . $definition['file'];
				$prefix = "feature_{$key}__";
				$featureData = self::featureData($data, $prefix);
				$fields = self::featureFields($definition, $featureData);
				self::prefixFields($fields, $prefix);
				$children = iterator_to_array($fields->children());
				foreach ($children as $field) {
					$section->add($field);
				}
			}

			$root->add($section);
		}

		return $root;
	}

	private static function featureFields(array $definition, array $data): InputfieldWrapper {
		$class = $definition['class'];
		if ($definition['config'] === 'static') {
			return $class::getConfigInputfields($data);
		}

		$feature = wire(new $class());
		foreach ($data as $name => $value) $feature->set($name, $value);
		$wrapper = wire(new InputfieldWrapper());

		if ($class === JigsawWarmUpFeature::class) {
			$feature->getConfigInputfields($wrapper);
		} else {
			$feature->getConfigInputfields($wrapper);
		}
		return $wrapper;
	}

	private static function featureData(array $data, string $prefix): array {
		$result = [];
		foreach ($data as $name => $value) {
			if (str_starts_with((string)$name, $prefix)) {
				$result[substr((string)$name, strlen($prefix))] = $value;
			}
		}
		return $result;
	}

	private static function prefixFields(InputfieldWrapper $wrapper, string $prefix): void {
		$fields = [];
		self::collectFields($wrapper, $fields);
		$names = [];

		foreach ($fields as $field) {
			$name = (string)$field->attr('name');
			if ($name !== '') $names[$name] = $prefix . $name;
		}

		foreach ($fields as $field) {
			$name = (string)$field->attr('name');
			if ($name !== '' && isset($names[$name])) {
				$field->attr('name', $names[$name]);
			}

			$showIf = (string)$field->showIf;
			foreach ($names as $old => $new) {
				$showIf = preg_replace('/(?<![A-Za-z0-9_])' . preg_quote($old, '/') . '(?=[!<>=])/', $new, $showIf);
			}
			if ($showIf !== '') $field->showIf = $showIf;

			if ($field instanceof InputfieldMarkup && is_string($field->value)) {
				$value = $field->value;
				$value = str_replace('[name="', '[name="' . $prefix, $value);
				foreach ($names as $old => $new) {
					$value = str_replace(
						['Inputfield_' . $old, '#Inputfield_' . $old],
						['Inputfield_' . $new, '#Inputfield_' . $new],
						$value
					);
				}
				$field->value = $value;
			}
		}
	}

	private static function collectFields(InputfieldWrapper $wrapper, array &$fields): void {
		foreach ($wrapper->children() as $field) {
			$fields[] = $field;
			if ($field instanceof InputfieldWrapper) self::collectFields($field, $fields);
		}
	}

	private static function iconFor(string $key): string {
		return [
			'diagnostics' => 'stethoscope',
			'backup' => 'database',
			'ssl' => 'lock',
			'warmup' => 'fire',
			'announcement' => 'bullhorn',
			'adminbar' => 'bars',
			'languages' => 'language',
			'editor' => 'code',
			'uninstaller' => 'trash',
			'fields' => 'table',
			'context' => 'magic',
		][$key] ?? 'puzzle-piece';
	}
}
