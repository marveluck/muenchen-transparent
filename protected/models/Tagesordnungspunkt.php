<?php

/**
 * This is the model class for table "tagesordnungspunkte".
 *
 * The followings are the available columns in table 'tagesordnungspunkte':
 * @property integer $id
 * @property integer $vorgang_id
 * @property string $datum_letzte_aenderung
 * @property integer $antrag_id
 * @property string $gremium_name
 * @property integer $gremium_id
 * @property integer $sitzungstermin_id
 * @property string $sitzungstermin_datum
 * @property string $beschluss_text
 * @property string $entscheidung
 * @property integer $top_pos
 * @property integer|null $top_id
 * @property string $top_nr
 * @property int $top_ueberschrift
 * @property string $top_betreff
 * @property string $status
 *
 * The followings are the available model relations:
 * @property Termin $sitzungstermin
 * @property Gremium $gremium
 * @property Antrag $antrag
 * @property Dokument[] $dokumente
 */
class Tagesordnungspunkt extends CActiveRecord implements IRISItemHasDocuments
{
    public const STATUS_NONPUBLIC = 'geheim';

    /**
     * Returns the static model of the specified AR class.
     * @param string $className active record class name.
     * @return Tagesordnungspunkt the static model class
     */
    public static function model($className = __CLASS__)
    {
        return parent::model($className);
    }

    /**
     * @return string the associated database table name
     */
    public function tableName()
    {
        return 'tagesordnungspunkte';
    }

    /**
     * @return array validation rules for model attributes.
     */
    public function rules()
    {
        // NOTE: you should only define rules for those attributes that
        // will receive user inputs.
        return [
            ['top_betreff, sitzungstermin_id, sitzungstermin_datum, datum_letzte_aenderung', 'required'],
            ['antrag_id, gremium_id, sitzungstermin_id, top_ueberschrift, vorgang_id', 'numerical', 'integerOnly' => true],
            ['gremium_name', 'length', 'max' => 100],
            ['beschluss_text', 'length', 'max' => 500],
            ['created, modified', 'safe'],
        ];
    }

    /**
     * @return array relational rules.
     */
    public function relations()
    {
        // NOTE: you may need to adjust the relation name and the related
        // class name for the relations automatically generated below.
        return [
            'sitzungstermin' => [self::BELONGS_TO, 'Termin', 'sitzungstermin_id'],
            'gremium'        => [self::BELONGS_TO, 'Gremium', 'gremium_id'],
            'antrag'         => [self::BELONGS_TO, 'Antrag', 'antrag_id'],
            'dokumente'      => [self::HAS_MANY, 'Dokument', 'tagesordnungspunkt_id'],
            'vorgang'        => [self::BELONGS_TO, 'Vorgang', 'vorgang_id'],
        ];
    }

    /**
     * @return array customized attribute labels (name=>label)
     */
    public function attributeLabels()
    {
        return [
            'id'                     => 'ID',
            'vorgang_id'             => 'Vorgangs-ID',
            'antrag_id'              => 'Antrag',
            'gremium_name'           => 'Gremium Name',
            'gremium_id'             => 'Gremium',
            'sitzungstermin_id'      => 'Sitzungstermin',
            'sitzungstermin_datum'   => 'Sitzungstermin Datum',
            'beschluss_text'         => 'Beschluss',
            'entscheidung'           => 'Entscheidung',
            'datum_letzte_aenderung' => 'Letzte Änderung',
            'top_pos'                => 'TOP Position',
            'top_id'                 => 'TOP ID',
            'top_nr'                 => 'Tagesordnungspunkt',
            'top_ueberschrift'       => 'Ist Überschrift',
            'top_betreff'            => 'Betreff',
            'status'                 => 'Status'
        ];
    }

    /**
     * @throws CDbException|Exception
     */
    public function copyToHistory()
    {
        $history = new TagesordnungspunktHistory();
        $history->setAttributes($this->getAttributes(), false);
        try {
            if (!$history->save()) {
                RISTools::report_ris_parser_error("TagesordnungspunktHistory:moveToHistory Error", print_r($history->getErrors(), true));
                throw new Exception("Fehler");
            }
        } catch (CDbException $e) {
            if (!str_contains($e->getMessage(), "Duplicate entry")) throw $e;
        }

    }

    /**
     * @return OrtGeo[]
     */
    public function get_geo()
    {
        $return            = [];
        $strassen_gefunden = RISGeo::suche_strassen($this->top_betreff);
        $indexed           = [];
        foreach ($strassen_gefunden as $strasse_name) if (!in_array($strasse_name, $indexed)) {
            $indexed[] = $strasse_name;
            $geo       = OrtGeo::getOrCreate($strasse_name);
            if (is_null($geo)) continue;
            $return[] = $geo;
        }
        return $return;
    }

    /**
     * @return array
     */
    public function zugeordneteAntraegeHeuristisch()
    {
        $betreff = str_replace(["\n", "\r"], [" ", " "], $this->top_betreff);
        preg_match_all("/[0-9]{2}\-[0-9]{2} ?\/ ?[A-Z] ?[0-9]+/su", $betreff, $matches);

        $antraege = [];
        foreach ($matches[0] as $match) {
            /** @var Antrag $antrag */
            $antrag = Antrag::model()->findByAttributes(["antrags_nr" => Antrag::cleanAntragNr($match)]);
            if ($antrag) $antraege[] = $antrag;
            else $antraege[] = "Nr. " . $match;
        }
        return $antraege;
    }

    public function getTopNo(): string
    {
        if (preg_match('/^1\.(?<no>\d.*)/siu', $this->top_nr, $matches)) {
            return $matches['no'];
        } else {
            return $this->top_nr;
        }
    }

    public function getLink(array $add_params = []): string
    {
        if ($this->antrag) return $this->antrag->getLink($add_params);
        return $this->sitzungstermin->getLink($add_params);
    }

    public function getTypName(): string
    {
        return "Stadtratsbeschluss";
    }

    public function getName(bool $kurzfassung = false): string
    {
        if ($kurzfassung) {
            $betreff = str_replace(["\n", "\r"], [" ", " "], $this->top_betreff);
            $x       = explode(" Antrag Nr.", $betreff);
            $x       = explode("<strong>Antrag: </strong>", $x[0]);
            return RISTools::normalizeTitle($x[0]);
        } else {
            return RISTools::normalizeTitle($this->top_betreff);
        }
    }

    /**
     * @return Dokument[]
     */
    public function getDokumente()
    {
        return $this->dokumente;
    }

    public function getDate(): string
    {
        return $this->datum_letzte_aenderung;
    }


}
