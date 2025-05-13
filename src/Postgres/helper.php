<?php

namespace App\Database;

use App\Data\DocumentTypeName;
use App\Data\SampleType;
use App\Models\LocationV1;
use App\Models\PatientV1;
use App\Models\RecordV1;
use App\Models\SampleV1;
use App\Models\UploadedFileV1;
use Assert\Assert;

/**
 * @brief Helper functions for dealing with database.
 *
 * - Generate forms from a given table name.
 * - @TODO: Format a table to display data.
 * - Handles PG types and converts them to PHP types.
 */
class DbHelper
{
    public function __construct(private object $db) { }

    /**
     * Return connected db.
     *
     * @return object
     */
    public function connect()
    {
        return $this->db;
    }

    /**
     * @brief Generate form for a table.
     *
     * @param string               $tableName name of the table
     * @param array<string, mixed> $data      Data to
     * @param array<string>        $hide      hide these columns from the form
     * @param array<string>        $show      Show these columns (if not null hide everything)
     * @param array<string>        $sort      an array with sort order
     * @param array<string>        $readonly  list of columns that are only readonly
     * @param array<string, mixed> $dropdown  available dropdown options for a column as associative array
     * @param ?string              $submit    Label on the submit button
     *
     * @return string html form
     */
    public function dbTableForm(
        string $tableName,
        array $data,
        array $hide = [],
        array $show = [],
        array $sort = [],
        array $dropdown = [],
        array $readonly = [],
        ?string $submit = null
    ): string {
        // if show is not empty and $sort is empty, then we can use
        // $show as sort
        if ($show && (! $sort)) {
            // key => sort value which is just index.
            $sort = array_flip($show);
        }
        $schema = $this->getSchema($tableName, $sort);

        // @TODO: Write a more human readable sort for our forms.
        // sort($schema);

        $html = [];

        // these are default values that are hidden.
        $hide = ['version', 'created_at', 'last_edited', ...$hide];

        foreach ($schema as $column) {
            // @phpstan-ignore offsetAccess.notFound
            $name = $column['column_name'];

            if (count($show) > 0) {
                // If array $shown is not empty, then it takes precedence over $hide.
                $hidden = 'hidden';
                if (in_array($name, $show)) {
                    $hidden = '';
                }
            } else {
                $hidden = in_array($name, $hide) ? 'hidden' : '';
            }

            // @phpstan-ignore offsetAccess.notFound
            $default = $column['column_default'];

            $value = ($data[$name] ?? $default) ?? '';
            if (is_array($value)) {
                $value = json_encode($value);
                log_message('info', "Column `{$name}` value is an array. Casting to JSON string: `{$value}`.");
            }

            // Enum types are SELECT.
            // @phpstan-ignore offsetAccess.notFound
            $dtype = $column['data_type'];
            // @phpstan-ignore offsetAccess.notFound
            $enumtype = $column['enum_type'];

            // log_message('debug', ' column info from schema: '.json_encode($column));

            // @phpstan-ignore offsetAccess.notFound
            $isRequired = 'NO' === $column['is_nullable'] ? '*' : '';

            $html[] = "<div class='row'>";
            $html[] = "<label class='col-4 col-form-label' {$hidden}>" . self::idToLabel($name) . $isRequired . ' </label>';

            $html[] = "<div class='col-8'>";
            $html[] = $this->renderInput(
                $name,
                $value,
                type: $dtype,
                enumtype: $enumtype,
                hidden: $hidden,
                dropdown: $dropdown,
                isReadonly: in_array($name, $readonly)
            );
            $html[] = '</div>';

            $html[] = '</div>'; // end row.
        }

        // add submit button if available.
        if ($submit) {
            $html[] = "<div class='row justify-content-end mt-4'>";
            $html[] = form_submit(
                'submit',
                $submit,
                extra: [
                    'class' => 'btn btn-primary col-4',
                ]
            );
            $html[] = '</div>';
        }

        return implode(' ', $html);
    }

    /**
     * @brief Render a column into approximate HTML input field. Various
     * transforms are applied.
     *
     * @param string               $name     name of the column in the postgresql
     * @param string               $value    value of the $name (if not present, use default form schema
     * @param string               $type     Type of the column (postgres type e.g. USER-DEFINED, varchar etc.)
     * @param ?string              $enumtype if type is 'USER-DEFINED', name of the type
     * @param string               $hidden   If `true` hide it. (fixme: move to hidden in form_open).
     * @param array<string, mixed> $dropdown a column may have user-defined
     *                                       dropdown values
     *
     * @return string HTML
     */
    private function renderInput(
        string $name,
        string $value,
        string $type,
        ?string $enumtype = null,
        string $hidden = '',
        array $dropdown = [],
        bool $isReadonly = false,
    ): string {
        // log_message('debug', "Rendering input with name='{$name}' value='{$value}' type='{$type}'");
        $class = 'col-8 form-control';

        $inputType = 'text';

        $nameLower = strtolower($name);
        $isMultiple = false;
        $selected = [$value];

        if (str_contains($nameLower, 'email')) {
            $inputType = 'email';
        } elseif ('date' === $type) {
            $inputType = 'date';
        } elseif ('timestamp without time zone' === $type) {
            $inputType = 'datetime-local';
            $value = htmlDatetimeLocal($value);
        } elseif (str_contains($type, 'double') || 'numeric' === $type || 'integer' === $type) {
            $inputType = 'number';
        } elseif ('last_meal_types' === $nameLower) {
            log_message('debug', 'Column with name last_meal_types.');
            $selected = json_decode($value); // selected
            $type = 'USER-DEFINED';
            $enumtype = 'lastmealtype';
            $inputType = 'select';
            $isMultiple = true;
        }

        $extra = [
            'id' => $name,
            'class' => $class,
        ];
        if ('hidden' === $hidden) {
            $extra['hidden'] = true;
        }
        if ($isReadonly) {
            $extra['readonly'] = true;
        }
        if ($isMultiple) {
            $name .= '[]';
            $extra['multiple'] = true;
        }

        if ('USER-DEFINED' === $type) {
            Assert::that($enumtype)->notNull()->string();
            try {
                $enumValues = $this->getEnumVariants($enumtype);
                $enumValues = [
                    '' => '--Please Select--',
                    ...$enumValues,
                ];
                log_message('debug', "USER-DEFINED: Rendering $name. selected={$value}. Values = " . json_encode($enumValues));

                return $this->_formDropdown(
                    $name,
                    $enumValues,
                    $selected,
                    extra: $extra
                );
            } catch (\Exception $e) {
                log_message('info', "Column name {$name} is not an enum. Using default text." . $e->getMessage());
            }
        }

        if ($dropdown[$name] ?? false) {
            $dropdownValues = $dropdown[$name];
            $dropdownValues = [
                '' => '--Please Select--',
                ...$dropdownValues,
            ];
            log_message('debug', "Column {$name} has user-defined dropdown values: " . json_encode($dropdownValues));

            return $this->_formDropdown(
                $name,
                $dropdownValues,
                $value,
                extra: $extra
            );
        }

        return form_input(
            $name,
            value: $value,
            type: $inputType,
            extra: $extra,
        );
    }

    /**
     * @param array<string, string > $options
     * @param array<string, string|true> $extra
     * @param null|array<string>|string $selected
     */
    private function _formDropdown(string $name, array $options, null|string|array $selected, array $extra = []): string {
        // Keep the id fixed since jquery needs to find it.
        $extra['id'] = SELECTIZE_ID_PREFIX;
        return form_dropdown(
            $name,
            options: $options,
            selected: $selected,
            extra: $extra
        );
    }

    /**
     * Return schema of a table.
     *
     * @param array<string, int> $sort  Sort using these columns. Values to be used
     * during sorting is passed in this array.
     *
     * @return array{column_name: string, data_type: string, is_nullable: string, enum_type: string, column_default: string}
     */
    private function getSchema(string $tableName, array $sort = []): array
    {
        $schema = $this->db->table('information_schema.columns')
            ->select("column_name, data_type, is_nullable, case when (data_type = 'USER-DEFINED') then udt_name else data_type end as enum_type, column_default")
            ->where([
                'table_name' => $tableName,
            ])
            ->get()->getResultArray();

        if ($sort) {
            log_message('debug', 'Sorting schema using ' . json_encode($sort));
            usort($schema, fn ($a, $b) => ($sort[$a['column_name']] ?? 0) - ($sort[$b['column_name']] ?? 0));
        }

        return $schema;
    }

    /**
     * Return value of enums. It must be an array of pair 'value' => 'label'
     * where value is the value of `<option>` field and `label` is the text
     * displayed.
     *
     * @return array<string>
     */
    public function getEnumVariants(string $colName): array
    {
        // Thanks https://stackoverflow.com/a/30417601/1805129
        $values = $this->db->query("SELECT unnest(enum_range(null, null::{$colName}))")->getResultArray();

        $result = [];
        foreach ($values as $x) {
            $v = $x['unnest'];
            $result[$v] = idToLabel($v);
        }

        return $result;
    }

    /**
     * Checks if query results contains more than one result. If yes, emit a
     * warning and select the first entry.
     *
     * @param array<array<string, mixed >> $rows
     *
     * @return array<string, mixed>
     */
    private function expectedOne(array $rows, string $what): array
    {
        if (count($rows) > 1) {
            log_message('warning', "More than 1 rows found for query {$what}:" . json_encode($rows));
        }

        return $rows[0];
    }

    private function sampleTableName(): string
    {
        return 'samplev1';
    }

    private static function idToLabel(string $id): string
    {
        return ucwords(str_replace('_', ' ', $id));
    }

    /**
     * Make a composite record by fetching information from database's table.
     *
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    private function makeCompositeRecord(array $record): array
    {
        $patientUid = $record['patient_uid'];
        $record['patient'] = model(PatientV1::class)->where('uid', $patientUid)->first();

        // @phpstan-ignore offsetAccess.notFound
        $patientUid = $record['patient']['uid'];

        // Ideally we should have only one sample for each record.
        $record['samples'] = $this->dbGetSamplesForPatientUid($patientUid, composite: true);

        return $record;
    }

    /**
     * Fetch SampleV1 for a given patient.
     *
     * @return array<array<string, mixed>>
     */
    public function dbGetSamplesForPatientUid(string $patientUid, bool $composite): array
    {
        $samples = model(SampleV1::class)
            ->where('patient_uid', $patientUid)
            ->orderBy('last_edited DESC')->findAll();
        if (! $composite) {
            return $samples;
        } 

        $locationModel = model(LocationV1::class);
        foreach ($samples as $key => $sample) {
            $sample['location'] = $locationModel->where('uid', $sample['collection_location_id'])->first();
            // @phpstan-ignore offsetAccess.notFound
            $sample['documents'] = $this->getPatientMedicalDocuments($patientUid, $sample['uid'], regenerateUri: true);
            $samples[$key] = $sample;
        }

        return $samples;
    }

    /**
     * @brief Returns all document related with given pateint (and optional sample).
     *
     * @return array<array<string, mixed>>
     */
    public function getPatientMedicalDocuments(
        string $patientUid,
        ?string $sampleUid = null,
        ?DocumentTypeName $docType = null,
        bool $regenerateUri = false
    ): array
    {
        log_message('info', "Searching for documents for patient_uid=`{$patientUid}` and sample_uid=`{$sampleUid}`.");

        $helper = model(UploadedFileV1::class)
            ->select('uid,sample_uid,uri,document_type,original_filename,created_at,last_edited')
            ->whereIn('patient_uid', [$patientUid, uuidWithoutHyphen($patientUid)])
            ->where('is_valid', true);
        if ($sampleUid) {
            $helper = $helper->whereIn('sample_uid', [$sampleUid, uuidWithoutHyphen($sampleUid)]);
        }
        if ($docType) {
            $helper = $helper->where('document_type', $docType->value);
        }

        $results = $helper->findAll();

        if($regenerateUri) {
            log_message('info', "Regenerating temporary uri.");
            foreach($results as &$row) {
                $row['uri'] = service('ehr_storage')->url(
                    $patientUid,
                    $sampleUid,
                    docType: $row['document_type'],
                    filename: $row['original_filename']
                );
            }
        }

        log_message('debug', '> Found ' . json_encode($results));

        return $results;
    }

    /**
     * @brief Remove all keys that have `null` values from the array.
     *
     * @param array<string, string|null|int|bool> $data
     * @return array<string, string|null|int|bool> $data
     */
    public static function removeNull(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (! is_null($value)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @brief Remove all keys that are null or empty string. Useful to filter
     * POST.
     *
     * @param array<string, string|null|int|bool> $data
     * @return array<string, string|null|int|bool> $data
     */
    public static function removeNullAndEmptyString(array $data): array
    {
        $data = self::removeNull($data);
        $result = [];
        foreach ($data as $key => $value) {
            if (is_string($value) && '' !== trim($value)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @brief Return unique locations.
     *
     * @return array<array<string, mixed>>
     */
    public function getUniqueLocations(): array
    {
        return model(LocationV1::class)
            ->select('DISTINCT ON (name, address, department) uid, name, address, department')
            ->where('is_valid', true)
            ->findAll();
    }
}
