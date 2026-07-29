<?php namespace ProcessWire;

/**
 * ProcessFieldAudit
 *
 * Admin tool that lists every field in the system showing:
 *  - Field name, type, label
 *  - For FieldtypeMatrixType fields: configured identifier and display name
 *  - For FieldtypeRepeaterMatrix fields: full breakdown of all types and their fields
 *  - Which templates each field belongs to
 *
 * Part of the InputfieldMatrixType module package.
 *
 * Install: copy folder to /site/modules/ProcessFieldAudit/
 *          Admin → Modules → Refresh → Install "Field Audit"
 * Access:  Admin → Setup → Field Audit
 *
 * @author  Maxim Semenov <maxim@smnv.org> (smnv.org) — smnv.org
 * @version 1.0.0
 */
class ProcessFieldAudit extends Process {

    public static function getModuleInfo() {
        return [
            'title'   => 'Field Audit',
            'version' => 103,
            'summary' => 'Lists all fields with types, MatrixType identifiers and matrix membership',
            'author'  => 'Maxim Semenov',
            'href'    => 'https://smnv.org',
            'icon'    => 'table',
            'page'    => [
                'name'   => 'field-audit',
                'parent' => 'setup',
                'title'  => 'Field Audit',
            ],
        ];
    }

    public function ___execute() {

        // ── 1. Build matrix map ───────────────────────────────────────────────
        $matrixMap = [];
        foreach ($this->fields as $field) {
            if (!($field->type instanceof FieldtypeRepeaterMatrix)) continue;

            // getMatrixTypes() returns ['typeName' => typeId, ...]
            $types   = $field->type->getMatrixTypes($field);
            $tpl     = $this->templates->get('repeater_' . $field->name);

            // Read displayName directly from matrix{N}_label in field config
            $displayNames = [];
            $fdata = $field->getArray();
            foreach ($fdata as $k => $v) {
                if (preg_match('/^matrix(\d+)_name$/', $k, $m)) {
                    $n     = $m[1];
                    $label = $fdata["matrix{$n}_label"] ?? '';
                    $displayNames[(string)$v] = $label ?: $this->prettify((string)$v);
                }
            }

            // Read per-type field list from matrix field config (matrix{N}_fields)
            $typeFields = $this->readTypeFields($field, $types);

            $matrixMap[$field->name] = [];
            foreach ($types as $typeName => $typeId) {
                $matrixMap[$field->name][$typeName] = [
                    'id'          => (int)$typeId,
                    'displayName' => $displayNames[$typeName] ?? $this->prettify($typeName),
                    'fields'      => $typeFields[$typeName] ?? [],
                ];
            }
        }

        // ── 2. Collect all fields ─────────────────────────────────────────────
        $allFields = [];
        foreach ($this->fields as $field) {
            $entry = [
                'field'         => $field,
                'typeName'      => $field->type->className(),
                'label'         => $field->label ?: '',
                'matrixSlug'    => null,
                'matrixDisplay' => null,
                'usedInMatrix'  => [],
                'templates'     => [],
            ];

            if ($field->type instanceof FieldtypeMatrixType) {
                $inp = $field->getInputfield($this->wire('page'), $field);
                if ($inp) {
                    $entry['matrixSlug']    = $inp->matrixTypeName    ?? null;
                    $entry['matrixDisplay'] = $inp->matrixDisplayName ?? null;
                }
            }

            foreach ($this->templates as $tpl) {
                if ($tpl->fields->has($field)) {
                    $entry['templates'][] = $tpl->name;
                }
            }

            $allFields[$field->name] = $entry;
        }

        // ── 3. Cross-reference ────────────────────────────────────────────────
        foreach ($matrixMap as $mfName => $types) {
            foreach ($types as $typeName => $typeData) {
                foreach ($typeData['fields'] as $fname => $flabel) {
                    if (isset($allFields[$fname])) {
                        $allFields[$fname]['usedInMatrix'][$mfName] = $typeName;
                    }
                }
            }
        }

        ksort($allFields);

        $out  = $this->styles();
        $out .= $this->renderMatrices($matrixMap);
        $out .= $this->renderTable($allFields);
        return $out;
    }

    // ── Read per-type field list from field config ────────────────────────────

    protected function readTypeFields(Field $mf, array $types) {
        $result = [];
        $data   = $mf->getArray();

        // Build N => typeName map from matrix{N}_name keys
        // N is the numeric suffix in the config key, NOT the sort order or typeId
        $nToName = [];
        foreach ($data as $k => $v) {
            if (preg_match('/^matrix(\d+)_name$/', $k, $m)) {
                $nToName[(int)$m[1]] = (string)$v;
            }
        }

        // Initialize result for all known types
        foreach ($types as $typeName => $typeId) {
            $result[$typeName] = [];
        }

        // Read fields per N
        foreach ($nToName as $n => $typeName) {
            $fieldIds = $data["matrix{$n}_fields"] ?? [];
            if (!is_array($fieldIds)) {
                $fieldIds = array_filter(explode(',', (string)$fieldIds));
            }
            $fields = [];
            foreach ($fieldIds as $fid) {
                $fid = (int)$fid;
                if (!$fid) continue;
                $f = $this->fields->get($fid);
                if ($f) $fields[$f->name] = $f->label ?: $f->name;
            }
            $result[$typeName] = $fields;
        }

        return $result;
    }

    // ── Renderers ─────────────────────────────────────────────────────────────

    protected function renderMatrices(array $matrixMap) {
        if (empty($matrixMap)) return '';
        $out = '<h2 class="fa-h2">RepeaterMatrix fields (' . count($matrixMap) . ')</h2>';
        foreach ($matrixMap as $mfName => $types) {
            $mf  = $this->fields->get($mfName);
            $out .= '<div class="fa-matrix">';
            $out .= '<div class="fa-mhdr">'
                  . '<span class="fa-mname">' . htmlspecialchars($mfName) . '</span>'
                  . ($mf->label ? '<span class="fa-mlabel">' . htmlspecialchars($mf->label) . '</span>' : '')
                  . '<span class="fa-mcount">' . count($types) . ' type' . (count($types) !== 1 ? 's' : '') . '</span>'
                  . '</div>';
            if (empty($types)) {
                $out .= '<div class="fa-empty">No types — set Matrix Type Identifier on FieldtypeMatrixType fields</div>';
            } else {
                $out .= '<div class="fa-tgrid">';
                foreach ($types as $typeName => $td) {
                    $out .= '<div class="fa-type">'
                          . '<div class="fa-thdr">'
                          . '<span class="fa-slug">' . htmlspecialchars($typeName) . '</span>'
                          . '<span class="fa-tdisplay">' . htmlspecialchars($td['displayName']) . '</span>'
                          . '<span class="fa-tid">#' . $td['id'] . '</span>'
                          . '</div>';
                    if (!empty($td['fields'])) {
                        $out .= '<div class="fa-tfields">';
                        foreach ($td['fields'] as $fn => $fl) {
                            $out .= '<span class="fa-fpill" title="' . htmlspecialchars($fl) . '">'
                                  . htmlspecialchars($fn) . '</span>';
                        }
                        $out .= '</div>';
                    } else {
                        $out .= '<div class="fa-none-small">no fields</div>';
                    }
                    $out .= '</div>';
                }
                $out .= '</div>';
            }
            $out .= '</div>';
        }
        return $out;
    }

    protected function renderTable(array $allFields) {
        $out  = '<h2 class="fa-h2" style="margin-top:32px">All fields (' . count($allFields) . ')</h2>';
        $out .= '<div class="fa-bar">'
              . '<input type="text" id="fa-q" placeholder="Filter by name or type…" oninput="faF()">'
              . '<label><input type="checkbox" id="fa-om" onchange="faF()"> MatrixType only</label>'
              . '<label><input type="checkbox" id="fa-hs" onchange="faF()" checked> Hide system fields</label>'
              . '</div>';
        $out .= '<table class="fa-tbl" id="fa-tbl"><thead><tr>'
              . '<th>Field name</th><th>Type</th><th>Label</th>'
              . '<th>Matrix slug</th><th>Display name</th>'
              . '<th>Used in matrix → type</th><th>Templates</th>'
              . '</tr></thead><tbody>';

        $sysPfx = ['_', 'process_', 'admin_', 'roles', 'permissions'];

        foreach ($allFields as $name => $e) {
            $isMatrix  = str_ends_with($e['typeName'], 'MatrixType') || str_ends_with($e['typeName'], 'RepeaterMatrix');
            $shortType = preg_replace('/^Fieldtype/', '', $e['typeName']);
            $isSys     = preg_match('/^(repeater_|_)/', $name)
                      || in_array($name, ['title','name','status','sort','include','created','modified','createdUser','modifiedUser','email','pass','language','roles','permissions']);

            $slugCell    = $e['matrixSlug']
                         ? '<span class="fa-slug">' . htmlspecialchars($e['matrixSlug']) . '</span>'
                         : '<span class="fa-dash">—</span>';
            $displayCell = $e['matrixDisplay'] ? htmlspecialchars($e['matrixDisplay']) : '<span class="fa-dash">—</span>';

            $mxCell = '';
            foreach ($e['usedInMatrix'] as $mf => $mt) {
                $mxCell .= '<span class="fa-mf">' . htmlspecialchars($mf) . '</span> '
                         . '<span class="fa-mt">' . htmlspecialchars($mt) . '</span> ';
            }
            if (!$mxCell) $mxCell = '<span class="fa-dash">—</span>';

            $tc = count($e['templates']);
            $tplCell = $tc
                ? '<details><summary>' . $tc . ' tpl</summary><div class="fa-tlist">' . implode(', ', array_map('htmlspecialchars', $e['templates'])) . '</div></details>'
                : '<span class="fa-dash">unused</span>';

            $out .= '<tr'
                  . ' class="' . ($isMatrix ? 'fa-rm ' : '') . ($isSys ? 'fa-sys' : '') . '"'
                  . ' data-name="' . htmlspecialchars($name) . '"'
                  . ' data-type="' . htmlspecialchars(strtolower($shortType)) . '"'
                  . ' data-matrix="' . ($isMatrix ? '1' : '0') . '"'
                  . ' data-sys="' . ($isSys ? '1' : '0') . '">'
                  . '<td class="fa-fname">' . htmlspecialchars($name) . '</td>'
                  . '<td><span class="fa-ttag">' . htmlspecialchars($shortType) . '</span></td>'
                  . '<td class="fa-flabel">' . htmlspecialchars($e['label']) . '</td>'
                  . '<td>' . $slugCell . '</td>'
                  . '<td>' . $displayCell . '</td>'
                  . '<td>' . $mxCell . '</td>'
                  . '<td>' . $tplCell . '</td>'
                  . '</tr>';
        }

        $out .= '</tbody></table>';
        $out .= '<script>
function faF(){
    var q=document.getElementById("fa-q").value.toLowerCase();
    var om=document.getElementById("fa-om").checked;
    var hs=document.getElementById("fa-hs").checked;
    document.querySelectorAll("#fa-tbl tbody tr").forEach(function(r){
        var show=true;
        if(q && !r.dataset.name.includes(q) && !r.dataset.type.includes(q)) show=false;
        if(om && r.dataset.matrix!=="1") show=false;
        if(hs && r.dataset.sys==="1") show=false;
        r.style.display=show?"":"none";
    });
}
faF();
</script>';
        return $out;
    }

    protected function prettify($s) {
        $s = preg_replace('/^(matrix_|media_|details_|type_|repeater_)/', '', $s);
        return ucwords(str_replace('_', ' ', $s));
    }

    // ── Styles ────────────────────────────────────────────────────────────────

    protected function styles() {
        return '<style>
.fa-h2{font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;color:#8f8b82;border-bottom:1px solid #eceae4;padding-bottom:8px;margin-bottom:16px}
.fa-matrix{background:#fff;border:1px solid #eceae4;border-radius:8px;margin-bottom:10px;overflow:hidden}
.fa-mhdr{background:#1a1916;display:flex;align-items:center;gap:10px;padding:9px 14px}
.fa-mname{font-family:monospace;font-size:12px;font-weight:700;color:#fff}
.fa-mlabel{font-size:12px;color:#8f8b82}
.fa-mcount{margin-left:auto;font-family:monospace;font-size:10px;color:#6b6762;background:#2e2c29;padding:2px 8px;border-radius:10px}
.fa-tgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1px;background:#f5f4f0}
.fa-type{background:#fff;padding:10px 14px}
.fa-thdr{display:flex;align-items:center;gap:6px;margin-bottom:6px}
.fa-slug{font-family:monospace;font-size:10px;background:#E5482A;color:#fff;padding:2px 6px;border-radius:3px;white-space:nowrap}
.fa-tdisplay{font-size:12px;font-weight:500;color:#1a1916;flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.fa-tid{font-family:monospace;font-size:10px;color:#b8b4aa}
.fa-tfields{display:flex;flex-wrap:wrap;gap:3px}
.fa-fpill{font-family:monospace;font-size:10px;background:#f5f4f0;color:#4a4743;padding:2px 5px;border-radius:3px;cursor:default}
.fa-empty{font-size:11px;color:#b8b4aa;font-style:italic;padding:10px 14px}
.fa-none-small{font-size:10px;color:#dbd8d0;font-style:italic}
.fa-bar{display:flex;align-items:center;gap:14px;margin-bottom:10px}
.fa-bar input[type=text]{height:32px;padding:0 10px;border:1px solid #dbd8d0;border-radius:4px;font-size:13px;width:260px;outline:none;font-family:inherit}
.fa-bar input[type=text]:focus{border-color:#E5482A}
.fa-bar label{font-size:12px;display:flex;align-items:center;gap:6px;cursor:pointer;color:#6b6762;user-select:none}
.fa-tbl{width:100%;border-collapse:collapse;font-size:12px}
.fa-tbl th{text-align:left;font-size:10px;font-family:monospace;text-transform:uppercase;letter-spacing:.06em;color:#b8b4aa;padding:7px 10px;border-bottom:2px solid #eceae4;white-space:nowrap;background:#faf9f7;position:sticky;top:0;z-index:1}
.fa-tbl td{padding:6px 10px;border-bottom:1px solid #f5f4f0;vertical-align:top}
.fa-tbl tr:hover td{background:#faf9f7}
.fa-rm td{background:#fdf9f8}
.fa-sys{opacity:.45}
.fa-fname{font-family:monospace;font-size:11px;color:#1a1916;font-weight:600}
.fa-flabel{color:#6b6762}
.fa-ttag{font-family:monospace;font-size:10px;background:#f5f4f0;color:#4a4743;padding:1px 5px;border-radius:3px;white-space:nowrap}
.fa-mf{display:inline-block;font-family:monospace;font-size:10px;background:#1a1916;color:#fff;padding:1px 5px;border-radius:3px}
.fa-mt{display:inline-block;font-family:monospace;font-size:10px;background:#fdf0ed;color:#E5482A;padding:1px 5px;border-radius:3px}
.fa-dash{color:#dbd8d0;font-size:11px}
details summary{cursor:pointer;color:#E5482A;font-size:11px;list-style:none}
.fa-tlist{font-family:monospace;font-size:10px;color:#6b6762;margin-top:4px;line-height:1.8}
</style>';
    }
}