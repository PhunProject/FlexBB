<?php
/**
 * Jevix — средство автоматического применения правил набора текстов,
 * наделённое способностью унифицировать разметку HTML/XML документов,
 * контролировать перечень допустимых тегов и аттрибутов,
 * предотвращать возможные XSS-атаки в коде документов.
 * http://code.google.com/p/jevix/
 * https://github.com/livestreet/livestreet-framework/blob/master/libs/vendor/Jevix/jevix.class.php
 * https://raw.github.com/bezumkin/modx-jevix/master/core/components/jevix/model/jevix/jevix.core.php
 *
 * @author     ur001 <ur001ur001@gmail.com>, http://ur001.habrahabr.ru
 * @author     https://github.com/altocms/Jevix
 * @author     Agel_Nash <agel-nash@mail.ru>
 * @author     Visman <mio.visman@yandex.ru>
 * @version    2.3.0
 * @link       https://github.com/MioVisman/Jevix
 * @license    https://opensource.org/licenses/MIT The MIT License (MIT)
 */

declare(strict_types=1);

namespace MioVisman\Jevix;

use InvalidArgumentException;
use RuntimeException;

class Jevix
{
    const PRINATABLE  =     0x1;
    const ALPHA       =     0x2;
    const LAT         =     0x4;
    const RUS         =     0x8;
    const NUMERIC     =    0x10;
    const SPACE       =    0x20;
    const NAME        =    0x40;
    const URL         =   0x100;
    const NOPRINT     =   0x200;
    const PUNCTUATUON =   0x400;
    //const	          =   0x800;
    //const	          =  0x1000;
    const HTML_QUOTE  =  0x2000;
    const TAG_QUOTE   =  0x4000;
    const QUOTE_CLOSE =  0x8000;
    const NL          = 0x10000;
    const QUOTE_OPEN  =       0;

    const STATE_TEXT                    = 0;
    const STATE_TAG_PARAMS              = 1;
    const STATE_TAG_PARAM_VALUE         = 2;
    const STATE_INSIDE_TAG              = 3;
    const STATE_INSIDE_NOTEXT_TAG       = 4;
    const STATE_INSIDE_PREFORMATTED_TAG = 5;
    const STATE_INSIDE_CALLBACK_TAG     = 6;

    protected $tagsRules  = [];
#   protected $entities1  = ['"' => '&quot;', "'" => '&#39;', '&' => '&amp;', '<' => '&lt;', '>' => '&gt;'];
    protected $entities2  = ['<' => '&lt;', '>' => '&gt;', '"' => '&quot;'];
    protected $textQuotes = [['«', '»'], ['„', '“']];

    protected $dash                 = " — ";
#   protected $apostrof             = "’";
    protected $dotes                = "…";
    protected $nl                   = "\n";
    protected $defaultTagParamRules = [
        'href'   => '#link',
        'src'    => '#image',
        'width'  => '#size',
        'height' => '#size',
        'text'   => '#text',
        'title'  => '#text'
    ];

    protected $text;
    protected $textBuf;
    protected $textLen                 = 0;
    protected $curPos;
    protected $curCh;
    protected $curChOrd;
    protected $curChClass;
    protected $curParentTag;
    protected $states;
    protected $quotesOpened            = 0;
#   protected $brAdded                 = 0;
    protected $state;
    protected $tagsStack;
    protected $openedTag;
    protected $autoReplace;
    protected $allowedProtocols        = ['#image' => 'http:|https:', '#link' => 'http:|https:|ftp:|mailto:'];
    protected $allowedProtocolsDefault = ['http', 'https', 'ftp'];
    protected $skipProtocol            = ['#image' => true, '#link' => true];
    protected $autoPregReplace;
    protected $isXHTMLMode             = false; // <br>, <img>
    protected $br                      = '<br>';
    protected $eFlags                  = \ENT_HTML5 | \ENT_QUOTES;
    protected $isAutoBrMode            = true; // \n = <br>
    protected $isAutoLinkMode          = true;
    protected $noTypoMode              = false;
#   protected $outBuffer               = '';
    protected $errors;


    /**
     * Константы для класификации тегов
     */
    const TR_TAG_ALLOWED       = 1;  // Тег позволен
    const TR_PARAM_ALLOWED     = 2;  // Параметр тега позволен (a->title, a->src, i->alt)
    const TR_PARAM_REQUIRED    = 3;  // Параметр тега являтся необходимым (a->href, img->src)
    const TR_TAG_SHORT         = 4;  // Тег может быть коротким (img, br)
    const TR_TAG_CUT           = 5;  // Тег необходимо вырезать вместе с контентом (script, iframe)
    const TR_TAG_CHILD         = 6;  // Тег может содержать другие теги
    const TR_TAG_CONTAINER     = 7;  // Тег может содержать лишь указанные теги. В нём не может быть текста
    const TR_TAG_CHILD_TAGS    = 8;  // Теги которые может содержать внутри себя другой тег
    const TR_TAG_PARENT        = 9;  // Тег в котором должен содержаться данный тег
    const TR_TAG_PREFORMATTED  = 10; // Преформатированные тег, в котором всё заменяется на HTML сущности типа <pre> сохраняя все отступы и пробелы
    const TR_PARAM_AUTO_ADD    = 11; // Auto add parameters + default values (a->rel[=nofollow])
    const TR_TAG_NO_TYPOGRAPHY = 12; // Отключение типографирования для тега
    const TR_TAG_IS_EMPTY      = 13; // Не короткий тег с пустым содержанием имеет право существовать
    const TR_TAG_NO_AUTO_BR    = 14; // Тег в котором не нужна авто-расстановка <br>
    const TR_TAG_CALLBACK      = 15; // Тег обрабатывается callback-функцией - в обработку уходит только контент тега (короткие теги не обрабатываются)
    const TR_TAG_BLOCK_TYPE    = 16; // Тег после которого не нужна автоподстановка <br>
    const TR_TAG_CALLBACK_FULL = 17; // Тег обрабатывается callback-функцией - в обработку уходит весь тег
    const TR_PARAM_COMBINATION = 18; // Проверка на возможные комбинации значений параметров тега

    /**
     * Классы символов генерируются symclass.php
     *
     * @var array
     */
    protected $chClasses = [
        0    => 512,
        1    => 512,
        2    => 512,
        3    => 512,
        4    => 512,
        5    => 512,
        6    => 512,
        7    => 512,
        8    => 512,
        9    => 32,
        10   => 66048,
        11   => 512,
        12   => 512,
        13   => 66048,
        14   => 512,
        15   => 512,
        16   => 512,
        17   => 512,
        18   => 512,
        19   => 512,
        20   => 512,
        21   => 512,
        22   => 512,
        23   => 512,
        24   => 512,
        25   => 512,
        26   => 512,
        27   => 512,
        28   => 512,
        29   => 512,
        30   => 512,
        31   => 512,
        32   => 32,
        97   => 71,
        98   => 71,
        99   => 71,
        100  => 71,
        101  => 71,
        102  => 71,
        103  => 71,
        104  => 71,
        105  => 71,
        106  => 71,
        107  => 71,
        108  => 71,
        109  => 71,
        110  => 71,
        111  => 71,
        112  => 71,
        113  => 71,
        114  => 71,
        115  => 71,
        116  => 71,
        117  => 71,
        118  => 71,
        119  => 71,
        120  => 71,
        121  => 71,
        122  => 71,
        65   => 71,
        66   => 71,
        67   => 71,
        68   => 71,
        69   => 71,
        70   => 71,
        71   => 71,
        72   => 71,
        73   => 71,
        74   => 71,
        75   => 71,
        76   => 71,
        77   => 71,
        78   => 71,
        79   => 71,
        80   => 71,
        81   => 71,
        82   => 71,
        83   => 71,
        84   => 71,
        85   => 71,
        86   => 71,
        87   => 71,
        88   => 71,
        89   => 71,
        90   => 71,
        1072 => 11,
        1073 => 11,
        1074 => 11,
        1075 => 11,
        1076 => 11,
        1077 => 11,
        1078 => 11,
        1079 => 11,
        1080 => 11,
        1081 => 11,
        1082 => 11,
        1083 => 11,
        1084 => 11,
        1085 => 11,
        1086 => 11,
        1087 => 11,
        1088 => 11,
        1089 => 11,
        1090 => 11,
        1091 => 11,
        1092 => 11,
        1093 => 11,
        1094 => 11,
        1095 => 11,
        1096 => 11,
        1097 => 11,
        1098 => 11,
        1099 => 11,
        1100 => 11,
        1101 => 11,
        1102 => 11,
        1103 => 11,
        1040 => 11,
        1041 => 11,
        1042 => 11,
        1043 => 11,
        1044 => 11,
        1045 => 11,
        1046 => 11,
        1047 => 11,
        1048 => 11,
        1049 => 11,
        1050 => 11,
        1051 => 11,
        1052 => 11,
        1053 => 11,
        1054 => 11,
        1055 => 11,
        1056 => 11,
        1057 => 11,
        1058 => 11,
        1059 => 11,
        1060 => 11,
        1061 => 11,
        1062 => 11,
        1063 => 11,
        1064 => 11,
        1065 => 11,
        1066 => 11,
        1067 => 11,
        1068 => 11,
        1069 => 11,
        1070 => 11,
        1071 => 11,
        48   => 337,
        49   => 337,
        50   => 337,
        51   => 337,
        52   => 337,
        53   => 337,
        54   => 337,
        55   => 337,
        56   => 337,
        57   => 337,
        34   => 57345,
        39   => 16385,
        46   => 1281,
        44   => 1025,
        33   => 1025,
        63   => 1281,
        58   => 1025,
        59   => 1281,
        1105 => 11,
        1025 => 11,
        47   => 257,
        38   => 257,
        37   => 257,
        45   => 257,
        95   => 257,
        61   => 257,
        43   => 257,
        35   => 257,
        124  => 257,
        64   => 257,
    ];

    /**
     * Установка конфигурационного флага для одного или нескольких тегов
     *
     * @param array|string $tags тег(и)
     * @param int $flag флаг
     * @param mixed $value значение флага
     * @param boolean $createIfNotExists если тег ещё не определён - создть его
     * @return $this
     */
    protected function _cfgSetTagsFlag($tags, int $flag, $value, bool $createIfNotExists = true): self
    {
        if (! \is_array($tags)) {
            $tags = [$tags];
        }

        foreach ($tags as $tag) {
            if (! isset($this->tagsRules[$tag])) {
                if ($createIfNotExists) {
                    $this->tagsRules[$tag] = [];
                } else {
                    $this->tagNameTest($tag);
                }
            }

            $this->tagsRules[$tag][$flag] = $value;
        }

        return $this;
    }

    /**
     * КОНФИГУРАЦИЯ: Разрешение тегов
     * Все не разрешённые теги считаются запрещёнными
     * @param array|string $tags тег(и)
     * @return $this
     */
    public function cfgAllowTags($tags): self
    {
        return $this->_cfgSetTagsFlag($tags, self::TR_TAG_ALLOWED, true);
    }

    /**
     * КОНФИГУРАЦИЯ: Коротие теги типа <img>
     * @param array|string $tags тег(и)
     * @return $this
     */
    public function cfgSetTagShort($tags): self
    {
        return $this->_cfgSetTagsFlag($tags, self::TR_TAG_SHORT, true, false);
    }

    /**
     * КОНФИГУРАЦИЯ: Преформатированные теги, в которых всё заменяется на HTML сущности типа <pre>
     * @param array|string $tags тег(и)
     * @return $this
     */
    public function cfgSetTagPreformatted($tags): self
    {
        return $this->_cfgSetTagsFlag($tags, self::TR_TAG_PREFORMATTED, true, false);
    }

    /**
     * КОНФИГУРАЦИЯ: Теги в которых отключено типографирование типа <code>
     * @param array|string $tags тег(и)
     * @return $this
     */
    public function cfgSetTagNoTypography($tags): self
    {
        return $this->_cfgSetTagsFlag($tags, self::TR_TAG_NO_TYPOGRAPHY, true, false);
    }

    /**
     * КОНФИГУРАЦИЯ: Не короткие теги которые не нужно удалять с пустым содержанием, например, <param name="code"
     * value="die!"></param>
     * @param array|string $tags тег(и)
     * @return $this
     */
    public function cfgSetTagIsEmpty($tags): self
    {
        return $this->_cfgSetTagsFlag($tags, self::TR_TAG_IS_EMPTY, true, false);
    }

    /**
     * КОНФИГУРАЦИЯ: Теги внутри который не нужна авто-расстановка <br>, например, <ul></ul> и <ol></ol>
     * @param array|string $tags тег(и)
     * @return $this
     */
    public function cfgSetTagNoAutoBr($tags): self
    {
        return $this->_cfgSetTagsFlag($tags, self::TR_TAG_NO_AUTO_BR, true, false);
    }

    /**
     * КОНФИГУРАЦИЯ: Тег необходимо вырезать вместе с контентом (script, iframe)
     * @param array|string $tags тег(и)
     * @return $this
     */
    public function cfgSetTagCutWithContent($tags): self
    {
        return $this->_cfgSetTagsFlag($tags, self::TR_TAG_CUT, true);
    }

    /**
     * КОНФИГУРАЦИЯ: После тега не нужно добавлять дополнительный <br>
     * @param array|string $tags тег(и)
     * @return $this
     */
    public function cfgSetTagBlockType($tags): self
    {
        return $this->_cfgSetTagsFlag($tags, self::TR_TAG_BLOCK_TYPE, true, false);
    }

    /**
     * КОНФИГУРАЦИЯ: Добавление разрешённых параметров тега
     * @param string $tag тег
     * @param string|array $params разрешённые параметры
     * @return $this
     */
    public function cfgAllowTagParams(string $tag, $params): self
    {
        $this->tagNameTest($tag);

        if (! \is_array($params)) {
            $params = [$params];
        }

        // Если ключа со списком разрешенных параметров не существует - создаём ео
        if (! isset($this->tagsRules[$tag][self::TR_PARAM_ALLOWED])) {
            $this->tagsRules[$tag][self::TR_PARAM_ALLOWED] = [];
        }

        foreach ($params as $key => $value) {
            if (\is_string($key)) {
                $this->tagsRules[$tag][self::TR_PARAM_ALLOWED][$key] = $value;
            } else {
                $this->tagsRules[$tag][self::TR_PARAM_ALLOWED][$value] = true;
            }
        }

        return $this;
    }

    /**
     * КОНФИГУРАЦИЯ: Добавление необходимых параметров тега
     * @param string $tag тег
     * @param string|array $params разрешённые параметры
     * @return $this
     */
    public function cfgSetTagParamsRequired(string $tag, $params): self
    {
        $this->tagNameTest($tag);

        if (! \is_array($params)) {
            $params = [$params];
        }

        // Если ключа со списком разрешенных параметров не существует - создаём его
        if (! isset($this->tagsRules[$tag][self::TR_PARAM_REQUIRED])) {
            $this->tagsRules[$tag][self::TR_PARAM_REQUIRED] = [];
        }

        foreach ($params as $param) {
            $this->tagsRules[$tag][self::TR_PARAM_REQUIRED][$param] = true;
        }

        return $this;
    }

    /**
     * КОНФИГУРАЦИЯ: Установка тегов которые может содержать тег-контейнер
     * @param string $tag тег
     * @param string|array $childs разрешённые теги
     * @param bool $isContainerOnly тег является только контейнером других тегов и не может содержать текст
     * @param bool $isChildOnly вложенные теги не могут присутствовать нигде кроме указанного тега
     * @return $this
     */
    public function cfgSetTagChilds(string $tag, $childs, bool $isContainerOnly = false, bool $isChildOnly = false): self
    {
        $this->tagNameTest($tag);

        if (! \is_array($childs)) {
            $childs = [$childs];
        }

        // Тег является контейнером и не может содержать текст
        if ($isContainerOnly) {
            $this->tagsRules[$tag][self::TR_TAG_CONTAINER] = true;
        }

        // Если ключа со списком разрешенных тегов не существует - создаём его
        if (! isset($this->tagsRules[$tag][self::TR_TAG_CHILD_TAGS])) {
            $this->tagsRules[$tag][self::TR_TAG_CHILD_TAGS] = [];
        }

        foreach ($childs as $child) {
            $this->tagsRules[$tag][self::TR_TAG_CHILD_TAGS][$child] = true;

            //  Указанный тег должен существовать в списке тегов
            $this->tagNameTest($child);

            if (! isset($this->tagsRules[$child][self::TR_TAG_PARENT])) {
                $this->tagsRules[$child][self::TR_TAG_PARENT] = [];
            }

            $this->tagsRules[$child][self::TR_TAG_PARENT][$tag] = true;

            // Указанные разрешённые теги могут находится только внтутри тега-контейнера
            if ($isChildOnly) {
                $this->tagsRules[$child][self::TR_TAG_CHILD] = true;
            }
        }

        return $this;
    }

    /**
     * КОНФИГУРАЦИЯ: Установка дефолтных значений для атрибутов тега
     * @param string $tag тег
     * @param string $param атрибут
     * @param string $value значение
     * @param bool $isRewrite заменять указанное значение дефолтным
     * @return $this
     */
    public function cfgSetTagParamDefault(string $tag, string $param, string $value, bool $isRewrite = false): self
    {
        $this->tagNameTest($tag);

        if (! isset($this->tagsRules[$tag][self::TR_PARAM_AUTO_ADD])) {
            $this->tagsRules[$tag][self::TR_PARAM_AUTO_ADD] = [];
        }

        $this->tagsRules[$tag][self::TR_PARAM_AUTO_ADD][$param] = [
            'value'   => $value,
            'rewrite' => $isRewrite,
        ];

        return $this;
    }

    /**
     * КОНФИГУРАЦИЯ: Устанавливаем callback-функцию на обработку содержимого тега
     * @param string $tag тег
     * @param mixed $callback функция
     * @return $this
     */
    public function cfgSetTagCallback(string $tag, $callback = null): self
    {
        $this->tagNameTest($tag);
        $this->tagsRules[$tag][self::TR_TAG_CALLBACK] = $callback;

        return $this;
    }

    /**
     * КОНФИГУРАЦИЯ: Устанавливаем callback-функцию на обработку тега (полностью)
     * @param string $tag тег
     * @param mixed $callback функция
     * @return $this
     */
    public function cfgSetTagCallbackFull(string $tag, $callback = null): self
    {
        $this->tagNameTest($tag);
        $this->tagsRules[$tag][self::TR_TAG_CALLBACK_FULL] = $callback;

        return $this;
    }

    /**
     * КОНФИГУРАЦИЯ: Устанавливаем комбинации значений параметров для тега
     *
     * @param string $tag тег
     * @param string $param атрибут
     * @param array $aCombinations Список комбинаций значений. Пример:
     *              array('myvalue'=>array('attr1'=>array('one','two'),'attr2'=>'other'))
     * @param bool $bRemove Удаляеть тег или нет, если в списке нет значения основного атрибута
     * @return $this
     */
    public function cfgSetTagParamCombination(string $tag, string $param, array $aCombinations, bool $bRemove = false): self
    {
        $this->tagNameTest($tag);

        if (! isset($this->tagsRules[$tag][self::TR_PARAM_COMBINATION])) {
            $this->tagsRules[$tag][self::TR_PARAM_COMBINATION] = [];
        }

        $this->tagsRules[$tag][self::TR_PARAM_COMBINATION][$param] = [
            'combination' => $this->arrayToLowerRec($aCombinations),
            'remove'      => $bRemove,
        ];

        return $this;
    }

    protected function arrayToLowerRec(array $array): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            if (\is_string($key)) {
                $key = \mb_strtolower($key, 'UTF-8');
            }

            if (\is_array($value)) {
                $value = $this->arrayToLowerRec($value);

            } elseif (\is_string($value)) {
                $value = \mb_strtolower($value, 'UTF-8');
            }

            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * КОНФИГУРАЦИЯ: Автозамена
     *
     * @param array $from с
     * @param array $to на
     * @return $this
     */
    public function cfgSetAutoReplace(array $from, array $to): self
    {
        $this->autoReplace = ['from' => $from, 'to' => $to];

        return $this;
    }

    /**
     * КОНФИГУРАЦИЯ: Автозамена с поддержкой регулярных выражений
     *
     * @param array $from с
     * @param array $to на
     * @return $this
     */
    public function cfgSetAutoPregReplace(array $from, array $to): self
    {
        $this->autoPregReplace = ['from' => $from, 'to' => $to];

        return $this;
    }

    /**
     * КОНФИГУРАЦИЯ: Устанавливает список разрешенных протоколов для ссылок (http, ftp и т.п.)
     *
     * @param array $aProtocols Список протоколов
     * @param bool $bClearDefault Удалить дефолтные протоколы
     * @return $this
     */
    public function cfgSetLinkProtocolAllow($aProtocols, bool $bClearDefault = false): self
    {
        return $this->cfgSetAllowedProtocols($aProtocols, $bClearDefault, '#link');
    }

    /**
     * КОНФИГУРАЦИЯ: Устанавливает список разрешенных протоколов (http, ftp и т.п.)
     *
     * @param array $aProtocols Список протоколов
     * @param bool $bClearDefault Удалить дефолтные протоколы
     * @param string|array $aParams Для каких параметров задавать
     * @return $this
     */
    public function cfgSetAllowedProtocols($aProtocols, $bClearDefault = false, $aParams = []): self
    {
        if (! \is_array($aProtocols)) {
            $aProtocols = [(string) $aProtocols];
        }

        if (! \is_array($aParams)) {
            $aParams = [(string) $aParams];
        }

        $bSkipProtocol = false;

        foreach ($aProtocols as $nKey => $sProtocol) {
            if (! $sProtocol) {
                // "Пустой" протокол - разрешено пропускать протокол для параметра
                $bSkipProtocol = true;
                unset($aProtocols[$nKey]);

            } elseif (':' === $sProtocol[-1]) {
                // Убираем двоеточие в конце протокола
                $aProtocols[$nKey] = \rtrim($sProtocol, ':');
            }
        }

        foreach ($aParams as $sParam) {
            if ($aProtocols) {
                if (
                    $bClearDefault
                    || ! isset($this->allowedProtocols[$sParam])
                ) {
                    $this->allowedProtocols[$sParam] = \implode(':|', $aProtocols) . ':';

                } else {
                    $this->allowedProtocols[$sParam] = \implode(':|', \array_merge($this->allowedProtocolsDefault, $aProtocols)) . ':';
                }
            }

            // Разрешено ли пропускать протокол для параметра
            if ($bSkipProtocol) {
                $this->skipProtocol[$sParam] = true;

            } else {
                $this->skipProtocol[$sParam] = false;
            }
        }

        return $this;
    }

    /**
     * КОНФИГУРАЦИЯ: Включение или выключение режима XTML
     *
     * @param boolean $isXHTMLMode
     * @return $this
     */
    public function cfgSetXHTMLMode(bool $isXHTMLMode): self
    {
        $this->br          = $isXHTMLMode ? '<br/>' : '<br>';
        $this->isXHTMLMode = $isXHTMLMode;
        $this->eFlags      = ($isXHTMLMode ? \ENT_XHTML : \ENT_HTML5) | \ENT_QUOTES;

        return $this;
    }

    /**
     * КОНФИГУРАЦИЯ: Включение или выключение режима замены новых строк на <br>
     *
     * @param boolean $isAutoBrMode
     * @return $this
     */
    public function cfgSetAutoBrMode(bool $isAutoBrMode): self
    {
        $this->isAutoBrMode = $isAutoBrMode;

        return $this;
    }

    /**
     * КОНФИГУРАЦИЯ: Включение или выключение режима автоматического определения ссылок
     *
     * @param boolean $isAutoLinkMode
     * @return $this
     */
    public function cfgSetAutoLinkMode(bool $isAutoLinkMode): self
    {
        $this->isAutoLinkMode = $isAutoLinkMode;

        return $this;
    }

    /**
     * КОНФИГУРАЦИЯ: Устанавливает символ перевода строки: \r\n, \r, \n
     *
     * @param string $nl
     * @return $this
     * @throws InvalidArgumentException
     */
    public function cfgSetNL(string $nl): self
    {
        if (\in_array($nl, ["\r\n", "\r", "\n"], true)) {
            $this->nl = $nl;

            return $this;

        } else {
            throw new InvalidArgumentException('Expected "\\r\\n", "\\r" or "\\n"');
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function tagNameTest(string $tag): void
    {
        if (! isset($this->tagsRules[$tag])) {
            throw new InvalidArgumentException("Tag $tag is missing in allowed tags list");
        }
    }

    /**
     * @param string $str
     * @return array
     */
    protected function strToArray(string $str): array
    {
        \preg_match_all('%.%su', $str, $chars);

        return $chars[0];
    }

    /**
     * @param $text
     * @param $errors
     * @return string
     */
    public function parse(string $text, &$errors): string
    {
        $this->curPos       = -1;
        $this->curCh        = ''; // null;
        $this->curChOrd     = 0;
        $this->state        = self::STATE_TEXT;
        $this->states       = [];
        $this->quotesOpened = 0;
        $this->noTypoMode   = false;
        $this->text         = $text;

        // Автозамена с регулярными выражениями
        $replacements = [];

        if (! empty($this->autoPregReplace)) {
            foreach ($this->autoPregReplace['from'] as $k => $v) {
                \preg_match_all($v, $this->text, $matches);

                foreach ($matches[0] as $k2 => $v2) {
                    $to   = \preg_replace($v, $this->autoPregReplace['to'][$k], $v2);
                    $hash = \sha1(\serialize($v2));

                    $replacements[$hash] = $to;
                    $this->text          = \str_replace($v2, $hash, $this->text);
                }
            }
        }

        // Авто растановка BR?
        if ($this->isAutoBrMode) {
            $this->text = \preg_replace('%<br/?>\r?\n?%i', $this->nl, $this->text);
        }

        $this->textBuf   = $this->strToArray($this->text);
        $this->textLen   = \count($this->textBuf);
        $this->getCh();
        $content         = '';
#       $this->outBuffer = '';
#       $this->brAdded   = 0;
        $this->tagsStack = [];
        $this->openedTag = null;
        $this->errors    = [];
        $this->skipSpaces();
        $this->anyThing($content);
        $errors          = $this->errors;

        if (! empty($this->autoReplace)) {
            $content = \str_ireplace($this->autoReplace['from'], $this->autoReplace['to'], $content);
        }

        if (! empty($replacements)) {
            $content = \str_replace(\array_keys($replacements), $replacements, $content);
        }

        return $content;
    }

    /**
     * Получение следующего символа из входной строки
     * @return string считанный символ
     */
    protected function getCh(): string
    {
        return $this->goToPosition($this->curPos + 1);
    }

    /**
     * Перемещение на указанную позицию во входной строке и считывание символа
     * @param int $position
     * @return string символ в указанной позиции
     */
    protected function goToPosition(int $position): string
    {
        $this->curPos = $position;

        if ($this->curPos < $this->textLen) {
            $this->curCh      = $this->textBuf[$this->curPos];
            $this->curChOrd   = \mb_ord($this->curCh, 'UTF-8');
            $this->curChClass = $this->getCharClass($this->curChOrd);

        } else {
            $this->curCh      = ''; // null;
            $this->curChOrd   = 0;
            $this->curChClass = 0;
        }

        return $this->curCh;
    }

    /**
     * Сохранить текущее состояние
     */
    protected function saveState(): int
    {
        $this->states[] = [
            'pos'   => $this->curPos,
            'ch'    => $this->curCh,
            'ord'   => $this->curChOrd,
            'class' => $this->curChClass,
        ];

        return \count($this->states) - 1;
    }

    /**
     * Восстановить
     * @param ?int $index
     * @throws RuntimeException
     */
    protected function restoreState(?int $index = null): void
    {
        if (empty($this->states)) {
            throw new RuntimeException('End of stack');
        }

        if ($index === null) {
            $state = \array_pop($this->states);

        } else {
            if (! isset($this->states[$index])) {
                throw new RuntimeException('Invalid stack index');
            }

            $state        = $this->states[$index];
            $this->states = \array_slice($this->states, 0, $index);
        }

        $this->curPos     = $state['pos'];
        $this->curCh      = $state['ch'];
        $this->curChOrd   = $state['ord'];
        $this->curChClass = $state['class'];
    }

    /**
     * Проверяет точное вхождение символа в текущей позиции
     * Если символ соответствует указанному автомат сдвигается на следующий
     *
     * @param string $ch
     * @param bool $skipSpaces
     * @return bool
     */
    protected function matchCh(string $ch, bool $skipSpaces = false): bool
    {
        if ($this->curCh == $ch) {
            $this->getCh();

            if ($skipSpaces) {
                $this->skipSpaces();
            }

            return true;
        }

        return false;
    }

    /**
     * Проверяет точное вхождение символа указанного класса в текущей позиции
     * Если символ соответствует указанному классу автомат сдвигается на следующий
     *
     * @param int $chClass класс символа
     * @param bool $skipSpaces
     * @return string|bool найденый символ или false
     */
    protected function matchChClass(int $chClass, bool $skipSpaces = false)
    {
        if ($this->curChClass & $chClass) {
            $ch = $this->curCh;
            $this->getCh();

            if ($skipSpaces) {
                $this->skipSpaces();
            }

            return $ch;
        }

        return false;
    }

    /**
     * Проверка на точное совпадение строки в текущей позиции
     * Если строка соответствует указанной автомат сдвигается на следующий после строки символ
     *
     * @param string $str
     * @param bool $skipSpaces
     * @return bool
     */
    protected function matchStr(string $str, bool $skipSpaces = false): bool
    {
        $this->saveState();
        $len  = \mb_strlen($str, 'UTF-8');
        $test = '';

        while (
            $len--
            && $this->curChClass
        ) {
            $test .= $this->curCh;
            $this->getCh();
        }

        if ($test == $str) {
            if ($skipSpaces) {
                $this->skipSpaces();
            }

            return true;
        }

        $this->restoreState();

        return false;
    }

    /**
     * Пропуск текста до нахождения указанного символа
     *
     * @param string $ch сиимвол
     * @return string найденый символ или false
     */
    protected function skipUntilCh(string $ch)
    {
        $chPos = \mb_strpos($this->text, $ch, $this->curPos, 'UTF-8');

        return $chPos ? $this->goToPosition($chPos) : false;
    }

    /**
     * Пропуск текста до нахождения указанной строки или символа
     *
     * @param string $str строка или символ ля поиска
     * @return bool
     */
    protected function skipUntilStr(string $str): bool
    {
        $str     = $this->strToArray($str);
        $firstCh = $str[0];
        $len     = \count($str);

        while ($this->curChClass) {
            if ($this->curCh == $firstCh) {
                $this->saveState();
                $this->getCh();
                $strOK = true;

                for ($i = 1; $i < $len; $i++) {
                    // Конец строки
                    if (! $this->curChClass) {
                        return false;
                    }

                    // текущий символ не равен текущему символу проверяемой строки?
                    if ($this->curCh != $str[$i]) {
                        $strOK = false;
                        break;
                    }

                    // Следующий символ
                    $this->getCh();
                }

                // При неудаче откатываемся с переходим на следующий символ
                if (! $strOK) {
                    $this->restoreState();

                } else {
                    return true;
                }
            }

            // Следующий символ
            $this->getCh();
        }

        return false;
    }

    /**
     * Возвращает класс символа
     *
     * @param int $ord
     * @return int
     */
    protected function getCharClass(int $ord): int
    {
        return $this->chClasses[$ord] ?? self::PRINATABLE;
    }

    /**
     * Пропуск пробелов
     *
     * @param int $count
     * @return bool
     */
    protected function skipSpaces(&$count = 0): bool
    {
        while ($this->curChClass == self::SPACE) {
            $this->getCh();
            ++$count;
        }

        return $count > 0;
    }

    /**
     *  Получает имя (тега, параметра) по принципу 1 символ далее цифра или символ
     *
     * @param string $name
     * @param bool $minus
     * @return bool
     */
    protected function name(&$name = '', bool $minus = false): bool
    {
        if ($this->curChClass & self::LAT) {
            $name .= $this->curCh;
            $this->getCh();

        } else {
            return false;
        }

        while (
            ($this->curChClass & self::NAME)
            || (
                $minus
                && $this->curCh == '-'
            )
        ) {
            $name .= $this->curCh;
            $this->getCh();
        }

        $this->skipSpaces();

        return true;
    }

    /**
     * @param string $tag
     * @param array $params
     * @param string $content
     * @param bool $short
     * @return bool
     */
    protected function tag(&$tag, &$params, &$content, &$short): bool
    {
        $this->saveState();
        $tag      = '';
        $params   = [];
        $content  = '';
        $short    = false;
        $closeTag = '';

        if (! $this->tagOpen($tag, $params, $short)) {
            return false;
        }

        // Короткая запись тега
        if ($short) {
            return true;
        }

        // Сохраняем кавычки и состояние
        //$oldQuotesopen = $this->quotesOpened;
        $oldState      = $this->state;
        $oldNoTypoMode = $this->noTypoMode;
        //$this->quotesOpened = 0;


        // Если в теге не должно быть текста, а только другие теги
        // Переходим в состояние self::STATE_INSIDE_NOTEXT_TAG
        if (! empty($this->tagsRules[$tag][self::TR_TAG_PREFORMATTED])) {
            $this->state = self::STATE_INSIDE_PREFORMATTED_TAG;

        } elseif (! empty($this->tagsRules[$tag][self::TR_TAG_CONTAINER])) {
            $this->state = self::STATE_INSIDE_NOTEXT_TAG;

        } elseif (! empty($this->tagsRules[$tag][self::TR_TAG_NO_TYPOGRAPHY])) {
            $this->noTypoMode = true;
            $this->state      = self::STATE_INSIDE_TAG;

        } elseif (
            \array_key_exists($tag, $this->tagsRules)
            && \array_key_exists(self::TR_TAG_CALLBACK, $this->tagsRules[$tag])
        ) {
            $this->state = self::STATE_INSIDE_CALLBACK_TAG;

        } else {
            $this->state = self::STATE_INSIDE_TAG;
        }

        // Контент тега
        \array_push($this->tagsStack, $tag);
        $this->openedTag = $tag;

        if ($this->state == self::STATE_INSIDE_PREFORMATTED_TAG) {
            $this->preformatted($content, $tag);

        } elseif ($this->state == self::STATE_INSIDE_CALLBACK_TAG) {
            $this->callback($content, $tag);

        } else {
            $this->anyThing($content, $tag);
        }

        \array_pop($this->tagsStack);
        $this->openedTag = ! empty($this->tagsStack) ? \array_pop($this->tagsStack) : null;
        $isTagClose      = $this->tagClose($closeTag);

        if (
            $isTagClose
            && $tag != $closeTag
        ) {
            $this->errors[] = ['Invalid closing %1$s tag. Expected closing %2$s tag', $closeTag, $tag];
            //$this->restoreState();
        }

        // Восстанавливаем предыдущее состояние и счетчик кавычек
        $this->state      = $oldState;
        $this->noTypoMode = $oldNoTypoMode;

        //$this->quotesOpened = $oldQuotesopen;

        return true;
    }

    /**
     * @param string $content
     * @param null|string $insideTag
     */
    protected function preformatted(&$content = '', $insideTag = null): void
    {
        $tmp         = '';
        $tmp_content = '';
        $start       = $this->curPos;
        $depth       = 0;

        while ($this->curChClass) {
            if ($this->curCh == '<') {
                $tmp = '';
                $tag = '';
                $this->saveState();
                // Пытаемся найти закрывающийся тег
                $isClosedTag = $this->tagClose($tag);

                // Возвращаемся назад, если тег был найден
                if ($isClosedTag) {
                    $this->restoreState();
                }

                // Если закрылось то, что открылось - заканчиваем и возвращаем true
                if (
                    $isClosedTag
                    && $tag == $insideTag
                ) {
                    // Если закрыли все открытые теги -
                    if ($depth === 0) {
                        // Сохраняем буфер и выходим
                        $content .= $tmp_content;

                        return;

                    } else {
                        $depth--;
                    }
                }

            // Открыт ноый preformatted тег
            } elseif (
                $this->curCh == '>'
                && $tmp == $insideTag
            ) {
                $depth++;

            } else {
                $tmp .= $this->curCh;
            }

            $tmp_content .= $this->entities2[$this->curCh] ?? $this->curCh;
            $this->getCh();
        }

        // Это на случай незакрытых вложенных тегов
        $this->goToPosition($start);

        while ($this->curChClass) {
            $tag         = '';
            $isClosedTag = $this->tagClose($tag);

            if ($isClosedTag) {
                $this->restoreState();
            }

            if (
                $isClosedTag
                && $tag == $insideTag
            ) {
                return;
            }

            $content .= $this->entities2[$this->curCh] ?? $this->curCh;
            $this->getCh();
        }
    }

    /**
     * @param string $content
     * @param null|string $insideTag
     */
    protected function callback(&$content = '', $insideTag = null): void
    {
        while ($this->curChClass) {
            if ($this->curCh == '<') {
                $tag = '';
                $this->saveState();
                // Пытаемся найти закрывающийся тег
                $isClosedTag = $this->tagClose($tag);

                // Возвращаемся назад, если тег был найден
                if ($isClosedTag) {
                    $this->restoreState();
                }

                // Если закрылось то, что открылось - заканчиваем и возвращаем true ????
                if (
                    $isClosedTag
                    && $tag == $insideTag
                ) {
                    if ($callback = $this->tagsRules[$tag][self::TR_TAG_CALLBACK]) {
                        $content = \call_user_func($callback, $content);
                    }

                    return;
                }
            }

            $content .= $this->curCh;
            $this->getCh();
        }
    }

    /**
     * @param string $name
     * @param array $params
     * @param bool $short
     * @return bool
     */
    protected function tagOpen(&$name, &$params, &$short = false): bool
    {
        $restore = $this->saveState();

        // Открытие
        if (!$this->matchCh('<')) {
            return false;
        }

        $this->skipSpaces();

        if (! $this->name($name)) {
            $this->restoreState();

            return false;
        }

        $name = \mb_strtolower($name, 'UTF-8');

        // Пробуем получить список атрибутов тега
        if (
            $this->curCh != '>'
            && $this->curCh != '/'
        ) {
            $this->tagParams($params);
        }

        // Короткая запись тега
        $short = ! empty($this->tagsRules[$name][self::TR_TAG_SHORT]);

        // Short && XHTML && !Slash || Short && !XHTML && !Slash = ERROR
        $slash = $this->matchCh('/');

        //if(($short && $this->isXHTMLMode && !$slash) || (!$short && !$this->isXHTMLMode && $slash)){
        if (
            ! $short
            && $slash
        ) {
            $this->restoreState();

            return false;
        }

        $this->skipSpaces();

        // Закрытие
        if (! $this->matchCh('>')) {
            $this->restoreState($restore);

            return false;
        }

        $this->skipSpaces();

        return true;
    }

    /**
     * @param array $params
     * @return bool
     */
    protected function tagParams(&$params = []): bool
    {
        $name = $value = '';

        while ($this->tagParam($name, $value)) {
            $params[$name] = $value;
            $name = $value = '';
        }

        return ! empty($params);
    }

    /**
     * @param string $name
     * @param string $value
     * @return bool
     */
    protected function tagParam(&$name, &$value): bool
    {
        $this->saveState();

        if (! $this->name($name, true)) {
            return false;
        }

        if (! $this->matchCh('=', true)) {
            // Стремная штука - параметр без значения <input type="checkbox" checked>, <td nowrap class=b>
            if (
                $this->curCh == '>'
                || ($this->curChClass & self::LAT)
            ) {
                $value = $name;

                return true;

            } else {
                $this->restoreState();

                return false;
            }
        }

        $quote = $this->matchChClass(self::TAG_QUOTE, true);

        if (! $this->tagParamValue($value, $quote)) {
            $this->restoreState();

            return false;
        }

        if (
            $quote
            && ! $this->matchCh($quote, true)
        ) {
            $this->restoreState();

            return false;
        }

        $this->skipSpaces();

        return true;
    }

    /**
     * @param string $value
     * @param bool|string $quote
     * @return bool
     */
    protected function tagParamValue(&$value, $quote): bool
    {
        if ($quote !== false) {
            // Нормальный параметр с кавычками. Получаем пока не кавычки и не конец
            $escape = false;

            while (
                $this->curChClass
                && (
                    $this->curCh != $quote
                    || $escape
                )
            ) {
                $escape = false;
#                // Экранируем символы HTML которые не могут быть в параметрах
#                $value .= $this->entities1[$this->curCh] ?? $this->curCh;
                // Экранировать будем в makeTag()
                $value .= $this->curCh;

                // Символ ескейпа <a href="javascript::alert(\"hello\")">
                if ($this->curCh == '\\') {
                    $escape = true;
                }

                $this->getCh();
            }

        } else {
            // Долбаный параметр без кавычек. Получаем его пока не пробел, не > и не конец
            while (
                $this->curChClass
                && ! ($this->curChClass & self::SPACE)
                && $this->curCh != '>'
            ) {
#                // Экранируем символы HTML которые не могут быть в параметрах
#                $value .= $this->entities1[$this->curCh] ?? $this->curCh;
                // Экранировать будем в makeTag()
                $value .= $this->curCh;
                $this->getCh();
            }
        }

        return true;
    }

    /**
     * @param string $name
     * @return bool
     */
    protected function tagClose(&$name): bool
    {
        $this->saveState();

        if (! $this->matchCh('<')) {
            return false;
        }

        $this->skipSpaces();

        if (! $this->matchCh('/')) {
            $this->restoreState();

            return false;
        }

        $this->skipSpaces();

        if (! $this->name($name)) {
            $this->restoreState();

            return false;
        }

        $name = \mb_strtolower($name, 'UTF-8');
        $this->skipSpaces();

        if (! $this->matchCh('>')) {
            $this->restoreState();

            return false;
        }

        return true;
    }

    /**
     * @param string $tag
     * @param array $params
     * @param string $content
     * @param bool $short
     * @param null|string $parentTag
     * @return mixed|string
     */
    protected function makeTag(string $tag, array $params, string $content, bool $short, $parentTag = null)
    {
        $this->curParentTag = $parentTag;
        $tag                = \mb_strtolower($tag, 'UTF-8');

        // Получаем правила фильтрации тега
        $tagRules = $this->tagsRules[$tag] ?? null;

        // Проверка - родительский тег - контейнер, содержащий только другие теги (ul, table, etc)
        $parentTagIsContainer = $parentTag && isset($this->tagsRules[$parentTag][self::TR_TAG_CONTAINER]);

        // Вырезать тег вместе с содержанием
        if (
            $tagRules
            && isset($this->tagsRules[$tag][self::TR_TAG_CUT])
        ) {
            return '';

        // Позволен ли тег
        } elseif (
            ! $tagRules
            || empty($tagRules[self::TR_TAG_ALLOWED])
        ) {
            return $parentTagIsContainer ? '' : $content;

        // Если тег находится внутри другого - может ли он там находится?
        } elseif (
            $parentTagIsContainer
            && ! isset($this->tagsRules[$parentTag][self::TR_TAG_CHILD_TAGS][$tag])
        ) {
            return '';

        // Тег может находится только внтури другого тега
        } elseif (
            isset($tagRules[self::TR_TAG_CHILD])
            && ! isset($tagRules[self::TR_TAG_PARENT][$parentTag])
        ) {
            return $content;
        }

        $resParams = [];
        $oldParams = [];

        foreach ($params as $param => $value) {
            $param             = \mb_strtolower($param, 'UTF-8');
            $value             = \trim($this->eDecode($value));
            $oldParams[$param] = $value;

            if ($value == '') {
                continue;
            }

            // Атрибут тега разрешён? Какие возможны значения? Получаем список правил
            $paramAllowedValues = $tagRules[self::TR_PARAM_ALLOWED][$param] ?? false;

            if (empty($paramAllowedValues)) {
                $this->errors[] = ['%2$s attribute is not allowed in %1$s tag', $tag, $param];

                continue;
            }

            // Попытка раскодировать все сущности в значении атрибута, например: j&#X41vascript:alert(1)
            $valueDecode = \preg_replace_callback(
                '%&#[xX]?\d+(?![;\d])%',
                function ($matches) {
                    $symbol = \html_entity_decode($matches[0] . ';', $this->eFlags, 'UTF-8');

                    return empty($symbol) ? $matches[0] : $symbol;
                },
                \html_entity_decode($value, $this->eFlags, 'UTF-8')
            );

            if (\preg_match('%javascript:%i', $valueDecode)) {
                $this->errors[] = ['Attempting to insert JavaScript into %2$s attribute of %1$s tag', $tag, $param];

                continue;
            }

            // Если есть список разрешённых параметров тега
            if (\is_array($paramAllowedValues)) {
                $bOK = true;

                // проверка на список доменов
                if (
                    isset($paramAllowedValues['#domain'])
                    && \is_array($paramAllowedValues['#domain'])
                ) {
                    $bOK       = false;
                    $sProtocol = '(' . $this->_getAllowedProtocols('#domain') . ')' . ($this->_getSkipProtocol('#domain') ? '?' : '');

                    // Support path-dependent rules per domain
                    foreach ($paramAllowedValues['#domain'] as $sDomain => $sPathRegex) {
                        if (\is_int($sDomain)) {
                            $sDomain    = $sPathRegex;
                            $sPathRegex = '';
                        }

                        $sDomain = \preg_quote($sDomain, '%');

                        if (\preg_match('%^' . $sProtocol . '//([\w\d]+\.)?' . $sDomain . '/' . $sPathRegex . '%ui', $value)) {
                            $bOK = true;
                            break;
                        }
                    }

                } elseif (! \in_array($value, $paramAllowedValues)) {
                    $bOK = false;
                }

                if (! $bOK) {
                    $this->errors[] = ['Invalid value for %2$s attribute [=%3$s] of %1$s tag', $tag, $param, $value];

                    continue;
                }

            // Если атрибут тега помечен как разрешённый, но правила не указаны - смотрим в массив стандартных правил для атрибутов
            } elseif (
                $paramAllowedValues === true
                && ! empty($this->defaultTagParamRules[$param])
            ) {
                $paramAllowedValues = $this->defaultTagParamRules[$param];
            }

            if (\is_string($paramAllowedValues)) {
                $bOK = true;

                if (
                    isset($paramAllowedValues[1])
                    && '[' === $paramAllowedValues[0]
                    && ']' === $paramAllowedValues[-1]
                ) {
                    if (! \preg_match(\substr($paramAllowedValues, 1, -1), $value)) {
                        $bOK = false;
                    }

                } else {
                    switch ($paramAllowedValues) {
                        case '#int':
                            if (! \is_numeric($value)) {
                                $bOK = false;
                            }

                            break;

                        case '#size':
                            if (
                                ! \preg_match('%^([1-9]\d*)(\%)?$%', $value, $matches)
                                || (
                                    ! empty($matches[2])
                                    && (int) $matches[1] > 100
                                )
                            ) {
                                $bOK = false;
                            }

                            break;

                        case '#text':
                            // $value = \htmlspecialchars($value);
                            // Экранировние значений атрибутов ниже по коду
                            break;

                        case '#link':
                            // Первый символ должен быть буквой, цифрой, #, / или точкой
                            if (! \preg_match('%^[\p{L}\p{N}/#.]%u', $value)) {
                                $bOK = false;

                                break;

                            // Пропускаем относительные url и якоря
                            // (что-то я не уверен в такой регулярке)
                            } elseif (\preg_match('%^(?:\.\.?/|/(?!/)|#)%', $value)) {
                                break;
                            }

                            // Если null, то проверка протокола/схемы провалена
                            // Пустая строка - нет схемы в url
                            // Или //, mailto:, https:// и т.д.
                            $schema = $this->schemaVerify($value, '#link');

                            if (null === $schema) {
                                $bOK = false;

                            // Нет слэшей и адрес похож на почту (проверка на разрешенные протоколы?)
                            } elseif (
                                '' === $schema
                                && false === \strpos($value, '/')
                                && \preg_match('%@[^.]+\.[^.]%', $value)
                            ) {
                                $value = "mailto:{$value}";

                            // Или адрес похож на домен (а еще регулярка у меня похожа на имя файла)
                            } elseif (
                                '' === $schema
                                && \preg_match('%^[\p{L}\p{N}][\p{L}\p{N}-]*[\p{L}\p{N}]\.[\p{L}\p{N}]%u', $value)
                            ) {
                                $value = "//{$value}";
                            }

                            break;

                        case '#image':
                            // Пропускаем относительные url
                            // (что-то я не уверен в такой регулярке)
                            if (\preg_match('%^(?:\.\.?/|/(?!/))%', $value)) {
                                break;
                            }

                            // Если null, то проверка протокола/схемы провалена
                            // Пустая строка - нет схемы в url
                            // Или //, mailto:, https:// и т.д.
                            $schema = $this->schemaVerify($value, '#link');

                            if (null === $schema) {
                                $bOK = false;

                            // Или адрес похож на домен (а еще регулярка у меня похожа на имя файла)
                            } elseif (
                                '' === $schema
                                && \preg_match('%^[\p{L}\p{N}][\p{L}\p{N}-]*[\p{L}\p{N}]\.[\p{L}\p{N}]%u', $value)
                            ) {
                                $value = "//{$value}";
                            }

                            break;
                    }
                }

                if (! $bOK) {
                    $this->errors[] = ['Invalid value for %2$s attribute [=%3$s] of %1$s tag', $tag, $param, $value];

                    continue;
                }
            }

            $resParams[$param] = $value;
        }

        // Проверка обязятельных параметров тега
        // Если нет обязательных параметров возвращаем только контент
        $requiredParams = isset($tagRules[self::TR_PARAM_REQUIRED]) ? \array_keys($tagRules[self::TR_PARAM_REQUIRED]) : [];

        if ($requiredParams) {
            foreach ($requiredParams as $requiredParam) {
                if (! isset($resParams[$requiredParam])) {
                    // Проверка для того, чтобы вторую ошибку не выводить для одного и того же атрибута
                    if (! isset($oldParams[$requiredParam])) {
                        $this->errors[] = ['Missing required %2$s attribute in %1$s tag', $tag, $requiredParam];
                    }

                    return $content;
                }
            }
        }

        // Автодобавляемые параметры
        if (! empty($tagRules[self::TR_PARAM_AUTO_ADD])) {
            foreach ($tagRules[self::TR_PARAM_AUTO_ADD] as $name => $aValue) {
                // If there isn't such attribute - setup it
                if (
                    ! \array_key_exists($name, $resParams)
                    || (
                        $aValue['rewrite']
                        && $resParams[$name] != $aValue['value']
                    )
                ) {
                    $resParams[$name] = $aValue['value'];
                }
            }
        }

        // Пустой некороткий тег удаляем кроме исключений
        if (
            empty($tagRules[self::TR_TAG_IS_EMPTY])
            && ! $short
            && $content == ''
        ) {
            return '';
        }

        // Проверка на допустимые комбинации
        if (isset($tagRules[self::TR_PARAM_COMBINATION])) {
            $aRuleCombin   = $tagRules[self::TR_PARAM_COMBINATION];
            $resParamsList = $resParams;

            foreach ($resParamsList as $param => $value) {
                $value = \mb_strtolower($value, 'UTF-8');

                if (isset($aRuleCombin[$param]['combination'][$value])) {
                    foreach ($aRuleCombin[$param]['combination'][$value] as $sAttr => $mValue) {
                        if (isset($resParams[$sAttr])) {
                            $bOK         = false;
                            $sValueParam = \mb_strtolower($resParams[$sAttr], 'UTF-8');

                            if (\is_string($mValue)) {
                                if ($mValue == $sValueParam) {
                                    $bOK = true;
                                }

                            } elseif (\is_array($mValue)) {
                                if (
                                    isset($mValue['#domain'])
                                    && \is_array($mValue['#domain'])
                                ) {
                                    if (! \preg_match('%javascript:%ui', $sValueParam)) {
                                        $sProtocol = '(' . $this->_getAllowedProtocols('#domain') . ')' . ($this->_getSkipProtocol('#domain') ? '?' : '');

                                        foreach ($mValue['#domain'] as $sDomain) {
                                            $sDomain = \preg_quote($sDomain, '%');

                                            if (\preg_match('%^' . $sProtocol . '//([\w\d]+\.)?' . $sDomain . '/%ui', $sValueParam)) {
                                                $bOK = true;
                                                break;
                                            }
                                        }
                                    }

                                } elseif (in_array($sValueParam, $mValue)) {
                                    $bOK = true;
                                }

                            } elseif ($mValue === true) {
                                $bOK = true;
                            }

                            if (! $bOK) {
                                $this->errors[] = ['Invalid value for %2$s attribute of %1$s tag (combination)', $tag, $sAttr];

                                unset($resParams[$sAttr]);
                            }
                        }
                    }

                } elseif (! empty($aRuleCombin[$param]['remove'])) {
                    $this->errors[] = ['Missing required %2$s attribute in %1$s tag (combination)', $tag, $param];

                    return '';
                }
            }
        }

        // Применить \htmlspecialchars() к значениям атрибутов
        $resParams = \array_map([$this, 'e'], $resParams);

        // Если тег обрабатывает "полным" колбеком
        if (isset($tagRules[self::TR_TAG_CALLBACK_FULL])) {
            $text = \call_user_func($tagRules[self::TR_TAG_CALLBACK_FULL], $content, $resParams, $tag);

        } else {
            // Собираем тег
            $text = "<{$tag}";

            // Параметры
            foreach ($resParams as $param => $value) {
                if ($value != '') {
                    $text .= " {$param}=\"{$value}\"";
                }
            }

            // Закрытие тега (если короткий то без контента)
            $text .= $short && $this->isXHTMLMode ? '/>' : '>';

            if (isset($tagRules[self::TR_TAG_CONTAINER])) {
                $text .= $this->nl;
            }

            if (! $short) {
                $text .= "{$content}</{$tag}>";
            }

            if ($parentTagIsContainer) {
                $text .= $this->nl;
            }

            if ($tag === 'br') {
                $text .= $this->nl;
            }
        }

        return $text;
    }

    /**
     * @return bool
     */
    protected function comment(): bool
    {
        if (! $this->matchStr('<!--')) {
            return false;
        }

        return $this->skipUntilStr('-->');
    }

    /**
     * @param string $content
     * @param null $parentTag
     * @return bool
     */
    protected function anyThing(&$content = '', $parentTag = null): bool
    {
        $this->skipNL();

        while ($this->curChClass) {
            $tag      = '';
            $params   = null;
            $text     = null;
            $shortTag = false;
            $name     = null;

            // Если мы находимся в режиме тега без текста
            // пропускаем контент пока не встретится <
            if (
                $this->state == self::STATE_INSIDE_NOTEXT_TAG
                && $this->curCh != '<'
            ) {
                $this->skipUntilCh('<');
            }

            // <Тег> кекст </Тег>
            if (
                $this->curCh == '<'
                && $this->tag($tag, $params, $text, $shortTag)
            ) {
                // Преобразуем тег в текст
                $tagText  = $this->makeTag($tag, $params, $text, $shortTag, $parentTag);
                $content .= $tagText;

                // Пропускаем пробелы после <br> и запрещённых тегов, которые вырезаются парсером
                if ($tag == 'br') {
                    $this->skipNL();

                } elseif (isset($this->tagsRules[$tag][self::TR_TAG_BLOCK_TYPE])) {
                    $count = 0;
                    $this->skipNL($count, 2);

                } elseif ($tagText == '') {
                    $this->skipSpaces();
                }

            // Коментарий <!-- -->
            } elseif (
                $this->curCh == '<'
                && $this->comment()
            ) {
                continue;

            // Конец тега или символ <
            } elseif ($this->curCh == '<') {
                // Если встречается <, но это не тег
                // то это либо закрывающийся тег либо знак <
                $this->saveState();

                if ($this->tagClose($name)) {
                    // Если это закрывающийся тег, то мы делаем откат
                    // и выходим из функции
                    // Но если мы не внутри тега, то просто пропускаем его
                    if (
                        $this->state == self::STATE_INSIDE_TAG
                        || $this->state == self::STATE_INSIDE_NOTEXT_TAG
                    ) {
                        $this->restoreState();

                        return false;

                    } else {
                        $this->errors[] = ['Not expected to close %1$s tag', $name];
                    }

                } else {
                    if ($this->state != self::STATE_INSIDE_NOTEXT_TAG) {
                        $content .= $this->entities2['<'];
                    }

                    $this->getCh();
                }

            // Текст
            } elseif ($this->text($text)) {
                $content .= $text;
            }
        }

        return true;
    }

    /**
     * Пропуск переводов строк, подсчет кол-ва
     *
     * @param int $count ссылка для возвращения числа переводов строк
     * @param int $limit максимальное число пропущенных переводов строк, при уставновке в 0 - не лимитируется
     * @return boolean
     */
    protected function skipNL(&$count = 0, int $limit = 0): bool
    {
        if (! ($this->curChClass & self::NL)) {
            return false;
        }

        $count++;
        $firstNL = $this->curCh;
        $nl      = $this->getCh();

        while ($this->curChClass & self::NL) {
            // Проверяем, не превышен ли лимит
            if (
                $limit > 0
                && $count >= $limit
            ) {
                break;
            }

            // Если символ новый строки такой же как и первый увеличиваем счетчик
            // новых строк. Это сработает при любых сочетаниях
            // \r\n\r\n, \r\r, \n\n - два перевода
            if ($nl == $firstNL) {
                $count++;
            }

            $nl = $this->getCh();
            // Между переводами строки могут встречаться пробелы
            $this->skipSpaces();
        }

        return true;
    }

    /**
     * @param string $dash
     * @return bool
     */
    protected function dash(&$dash): bool
    {
        if ($this->curCh != '-') {
            return false;
        }

        $dash = '';
        $this->saveState();
        $this->getCh();

        // Несколько подряд
        while ($this->curCh == '-') {
            $this->getCh();
        }

        if (
            ! $this->skipNL()
            && ! $this->skipSpaces()
        ) {
            $this->restoreState();

            return false;
        }

        $dash = $this->dash;

        return true;
    }

    /**
     * @param string $punctuation
     * @return bool
     */
    protected function punctuation(&$punctuation): bool
    {
        if (! ($this->curChClass & self::PUNCTUATUON)) {
            return false;
        }

        $this->saveState();
        $punctuation = $this->curCh;
        $this->getCh();

        // Проверяем ... и !!! и ?.. и !..
        if (
            $punctuation == '.'
            && $this->curCh == '.'
        ) {
            while ($this->curCh == '.') {
                $this->getCh();
            }

            $punctuation = $this->dotes;

        } elseif (
            $punctuation == '!'
            && $this->curCh == '!'
        ) {
            while ($this->curCh == '!') {
                $this->getCh();
            }

            $punctuation = '!!!';
        } elseif (
            (
                $punctuation == '?'
                || $punctuation == '!'
            )
            && $this->curCh == '.'
        ) {
            while ($this->curCh == '.') {
                $this->getCh();
            }

            $punctuation .= '..';
        }

        // Далее идёт слово - добавляем пробел
        if ($this->curChClass & self::RUS) {
            if ($punctuation != '.') {
                $punctuation .= ' ';
            }

            return true;

        // Далее идёт пробел, перенос строки, конец текста
        } elseif (
            ($this->curChClass & self::SPACE)
            || ($this->curChClass & self::NL)
            || ! $this->curChClass
        ) {
            return true;

        } else {
            $this->restoreState();

            return false;
        }
    }

    /**
     * @param string $num
     * @return bool
     */
    protected function number(&$num): bool
    {
        if (! ($this->curChClass & self::NUMERIC)) {
            return false;
        }

        $num = $this->curCh;
        $this->getCh();

        while ($this->curChClass & self::NUMERIC) {
            $num .= $this->curCh;
            $this->getCh();
        }

        return true;
    }

    /**
     * @param string $entityCh
     * @return bool
     */
    protected function htmlEntity(&$entityCh): bool
    {
        if ($this->curCh <> '&') {
            return false;
        }

        $this->saveState();
        $this->matchCh('&');

        if ($this->matchCh('#')) {
            $entityCode = 0;

            if (
                ! $this->number($entityCode)
                || ! $this->matchCh(';')
            ) {
                $this->restoreState();

                return false;
            }

            $entityCh = \html_entity_decode("&#$entityCode;", \ENT_COMPAT, 'UTF-8');

            return true;

        } else {
            $entityName = '';

            if (
                ! $this->name($entityName)
                || ! $this->matchCh(';')
            ) {
                $this->restoreState();

                return false;
            }

            $entityCh = \html_entity_decode("&$entityName;", \ENT_COMPAT, 'UTF-8');

            return true;
        }
    }

    /**
     * Кавычка
     *
     * @param bool $spacesBefore были до этого пробелы
     * @param string $quote кавычка
     * @param bool $closed закрывающаяся
     * @return bool
     */
    protected function quote(bool $spacesBefore, &$quote, &$closed): bool
    {
        $this->saveState();
        $quote = $this->curCh;
        $this->getCh();

        // Если не одна кавычка ещё не была открыта и следующий символ - не буква - то это нифига не кавычка
        if (
            $this->quotesOpened == 0
            && ! (
                ($this->curChClass & self::ALPHA)
                || ($this->curChClass & self::NUMERIC)
            )
        ) {
            $this->restoreState();

            return false;
        }

        // Закрывается тогда, одна из кавычек была открыта и (до кавычки не было пробела или пробел или пунктуация есть после кавычки)
        // Или, если открыто больше двух кавычек - точно закрываем
        $closed = $this->quotesOpened >= 2
            || (
                $this->quotesOpened > 0
                && (
                    ! $spacesBefore
                    || ($this->curChClass & self::SPACE)
                    || ($this->curChClass & self::PUNCTUATUON)
                )
            );

        return true;
    }

    /**
     * @param bool $closed
     * @param int $level
     * @return mixed
     */
    protected function makeQuote(bool $closed, int $level)
    {
        $levels = \count($this->textQuotes);

        if ($level > $levels) {
            $level = $levels;
        }

        return $this->textQuotes[$level][$closed ? 1 : 0];
    }

    /**
     * @param string $text
     * @return bool
     */
    protected function text(&$text): bool
    {
        $text    = '';
        $dash    = '';
        $newLine = true;
        $newWord = true; // Возможно начало нового слова
        $url     = null;
        $href    = null;
        //$punctuation = '';

        // Включено типографирование?
        //$typoEnabled = true;
        $typoEnabled = ! $this->noTypoMode;

        // Первый символ может быть <, это значит что tag() вернул false
        // и < к тагу не относится
        while (
            $this->curCh != '<'
            && $this->curChClass
        ) {
            $brCount     = 0;
            $spCount     = 0;
            $quote       = null;
            $closed      = false;
            $punctuation = null;
            $entity      = null;

            $this->skipSpaces($spCount);

            // автопреобразование сущностей...
            if (
                ! $spCount
                && $this->curCh == '&'
                && $this->htmlEntity($entity)
            ) {
                $text .= $this->entities2[$entity] ?? $entity;

            } elseif (
                $typoEnabled
                && ($this->curChClass & self::PUNCTUATUON)
                && $this->punctuation($punctuation)
            ) {
                // Автопунктуация выключена
                // Если встретилась пунктуация - добавляем ее
                // Сохраняем пробел перед точкой если класс следующий символ - латиница
                if (
                    $spCount
                    && $punctuation == '.'
                    && ($this->curChClass & self::LAT)
                ) {
                    $punctuation = " {$punctuation}";
                }

                $text   .= $punctuation;
                $newWord = true;

            } elseif (
                $typoEnabled
                && (
                    $spCount
                    || $newLine
                )
                && $this->curCh == '-'
                && $this->dash($dash)
            ) {
                // Тире
                $text   .= $dash;
                $newWord = true;

            } elseif (
                $typoEnabled
                && ($this->curChClass & self::HTML_QUOTE)
                && $this->quote($spCount > 0, $quote, $closed)
            ) {
                // Кавычки
                $this->quotesOpened += $closed ? -1 : 1;

                // Исправляем ситуацию если кавычка закрывается раньше чем открывается
                if ($this->quotesOpened < 0) {
                    $closed             = false;
                    $this->quotesOpened = 1;
                }

                $quote = $this->makeQuote($closed, $closed ? $this->quotesOpened : $this->quotesOpened - 1);

                if ($spCount) {
                    $quote = " {$quote}";
                }

                $text   .= $quote;
                $newWord = true;

            } elseif ($spCount > 0) {
                $text   .= ' ';
                // после пробелов снова возможно новое слово
                $newWord = true;

            } elseif (
                $this->isAutoBrMode
                && $this->skipNL($brCount)
            ) {
                // Перенос строки
                if (
                    $this->curParentTag
                    && isset($this->tagsRules[$this->curParentTag][self::TR_TAG_NO_AUTO_BR])
                    && (
                        \is_null($this->openedTag)
                        || isset($this->tagsRules[$this->openedTag][self::TR_TAG_NO_AUTO_BR])
                    )
                ) {
                    // пропускаем <br/>

                } else {
                    $br    = $this->br . $this->nl;
                    $text .= $brCount == 1 ? $br : $br . $br;
                }

                // Помечаем, что новая строка и новое слово
                $newLine = true;
                $newWord = true;
                // !!!Добавление слова

            } elseif (
                $newWord
                && $this->isAutoLinkMode
                && ($this->curChClass & self::LAT)
                && $this->openedTag != 'a'
                && $this->url($url, $href)
            ) {
                // URL
                $text .= $this->makeTag('a', ['href' => $href], $url, false);

            } elseif ($this->curChClass & self::PRINATABLE) {
                // Экранируем символы HTML которые нельзя сувать внутрь тега (но не те, которые не могут быть в параметрах)
                $text   .= $this->entities2[$this->curCh] ?? $this->curCh;
                $this->getCh();
                $newWord = false;
                $newLine = false;
                // !!!Добавление к слова

            } else {
                // Совершенно непечатаемые символы которые никуда не годятся
                $this->getCh();
            }
        }

        // Пробелы
        $this->skipSpaces();

        return $text != '';
    }

    /**
     * @param string $url
     * @param string $href
     * @return bool
     */
    protected function url(&$url, &$href): bool
    {
        $this->saveState();
        $url       = '';
        $urlChMask = self::URL | self::ALPHA | self::PUNCTUATUON;

        if ($this->matchStr('http://')) {
            while ($this->curChClass & $urlChMask) {
                $url .= $this->curCh;
                $this->getCh();
            }

            if (empty($url)) {
                $this->restoreState();

                return false;
            }

            $href = "http://{$url}";

        } elseif ($this->matchStr('https://')) {
            while ($this->curChClass & $urlChMask) {
                $url .= $this->curCh;
                $this->getCh();
            }

            if (empty($url)) {
                $this->restoreState();

                return false;
            }

            $href = "https://{$url}";

        } elseif ($this->matchStr('www.')) {
            while ($this->curChClass & $urlChMask) {
                $url .= $this->curCh;
                $this->getCh();
            }

            if (empty($url)) {
                $this->restoreState();

                return false;
            }

            $url  = "www.{$url}";
            $href = "http://{$url}";

        }

        if (! empty($url)) {
            if (\preg_match('%[.,?!:;-]+$%', $url, $matches)) {
                $count = - \strlen($matches[0]);
                $url   = \substr($url, 0, $count);
                $href  = \substr($href, 0, $count);
                $this->goToPosition($this->curPos + $count);
            }

            return true;

        } else {
            $this->restoreState();

            return false;
        }
    }

    /**
     * @param string $sParam
     * @return mixed
     */
    protected function _getAllowedProtocols(string $sParam)
    {
        if (! isset($this->allowedProtocols[$sParam])) {
            $this->cfgSetAllowedProtocols($this->allowedProtocolsDefault, true, $sParam);
        }

        return $this->allowedProtocols[$sParam];
    }

    /**
     * @param string $sParam
     * @return bool
     */
    protected function _getSkipProtocol(string $sParam): bool
    {
        return ! empty($this->skipProtocol[$sParam]);
    }

    protected function e(string $str): string
    {
        return \htmlspecialchars($str, $this->eFlags | \ENT_SUBSTITUTE, 'UTF-8', false);
    }

    protected function eDecode(string $str): string
    {
        return \htmlspecialchars_decode($str, $this->eFlags);
    }

    protected function schemaVerify(string $value, string $param): ?string
    {
        $pattern = '%^(' . $this->_getAllowedProtocols($param) . ')$%iu';

        if (! \preg_match('%^(?:([^:/\\\\]+:)(?://)?|//)%', $value, $schema)) {
            // схема не найдена
            return '';

        } else {
            // в url есть схема и она разрешена
            if (
                isset($schema[1])
                && \preg_match($pattern, $schema[1])
            ) {
                return $schema[0];

            // в url пустая схема и она разрешена
            } elseif (
                ! isset($schema[1])
                && $this->_getSkipProtocol($param)
            ) {
                return $schema[0];

            // провал проверки
            } else {
                return null;
            }
        }
    }
}
