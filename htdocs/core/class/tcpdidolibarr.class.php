<?php
/**
* SPDX-License-Identifier: GPL-3.0-or-later
* This file is part of Dolibarr ERP / CRM


This class is meant to provide an alternative to the traditional Dolibarr PDF generation system.
The main purpose is to get closer to respecting the SRP (Single Responsibility Principle) by separating
the layout of the PDF from the generation of the data (including calculation of tax, totals, etc.).

It also attempts to rely on TCPDI's capabilities when it comes to cursor positioning, header, footer
and page breaks: this makes the code easier to read (less calls to SetX(), SetY(), etc.).

It is meant to rely on TCPDF's HTML renderer for simple cases. TCPDF's HTML renderer is very flexible
but also very slow, limited and unpredictable compared to your browser's rendering engine, so it should
be tested thoroughly. Keep in mind that the renderer's output is sometimes better when using deprecated
inline attributes (like `width`, `align` etc.) instead of modern CSS. Some CSS is still possible.

The class also provides a way to generate simple tables without using HTML for cases where TCPDF's HTML
renderer would break the layout, especially for multi-page tables.

Please note that it is absolutely possible to implement your own table renderer if the one this class
provides is too limited.

*/

if (getDolGlobalInt('MAIN_DISABLE_TCPDI')) {
	throw new Exception('MAIN_DISABLE_TCPDI is on, cannot use DolibarrPdfTcpdi');
}
require_once TCPDF_PATH . 'tcpdf.php';
require_once TCPDI_PATH . 'tcpdi.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/shortcode.class.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/images.lib.php';

abstract class DolibarrPdfTcpdi extends TCPDI
{
	/**
	 * @var string Error code (or message)
	 */
	public $error = '';

	/** @var string $description  affichée par Dolibarr sur les pages de conf où on active / désactive les modèles */
	public $description;

	/** @var string $filePath  full path to the generated PDF file */
	public $filePath;

	/** @var string $moduleDir  Directory path of the module */
	protected $moduleDir;

	/** @var string objectElement  The element type for model listing */
	const objectElement = 'pdf';

	const orientation = 'P';

	public $margins = [
		'top' => 8,
		'right' => 10,
		'bottom' => 8, // space between the bottom of the footer and the bottom of the physical page
		'left' => 10,
		'body-footer' => 5, // space between the page body and the top of the footer
	];
	public $imgDPI = 100; // set to 300 for premium quality prints

	/**
	 * @var int $gabaritW  Gabarit = box that includes body + header + footer = full page - external margins.
	 */
	public $gabaritW = 0;
	public $defaultFontName = 'Helvetica';
	public $defaultFont = [
		'regular' => '',
		'bold' => 'B',
		'italic' => 'I',
	];
	public $footerHeight = 0;

	/** @var CommonObject|object $object */
	protected $object;

	/**
	 * Objet Translate distinct de $langs pour permettre de générer un PDF dans une autre langue que
	 * celle de l'interface de Dolibarr
	 * @var Translate $outputlangs
	 */
	protected $outputlangs;

	public $tPrefix; // prefix that is prepended to translation keys: override this prefix in child classes.

	public $TSub = []; // tableau contenant les données de substitution figurant sur le document

	/** @var DoliDB $db */
	protected $db;

	public $colors;

	/** @var string $logo  Chemin complet du logo */
	public $logo;

	/**
	 *  @var ShortCode $shortcode
	 *
	 * ShortCodes are a way to embed dynamic substitutions in the PDF (thanks @JohnBotella for providing the initial implementation).
	 * 
	 * How does that work?
	 * 
	 * In the HTML template, typical (non-shortcode) substitutions look like this: ${variableName}. 
	 * They are very straightforward, and you can do complex things with them by simply defining the variable in a complex way.
	 * But the more complex they grow, the more code you will need in initSubstitutionData() to define the variable.
	 * 
	 * Sometimes, you only want a value to be A or B depending on a condition.
	 * 
	 * In this case, you can use a shortcode like this `if` shortcode:
	 * 
	 * ${[if cond="A_IS_ENABLED" yes="A" no="B"]}
	 * 
	 * The `if` shortcode is defined as a closure passed to the `addShortCode` method.
	 * This class provides two other predefined shortcodes: `oddeven` or `color`.
	 * 
	 * - `oddeven` is used to alternate row colors in tables (because TCPDF doesn't support CSS3):
	 *   the first ${[oddeven]} will be replaced with "odd", the second with "even", the third with "odd" again, etc.
	 *   You can force the return to "odd" or "even" by using the `force` parameter: ${[oddeven force="odd"]}
	 * - `color` is used to define a color from a Dolibarr conf: ${[color conf="PDF_TABLE_ODD_ROW_BG_COLOR"]}
	 * 
	 * You can add your own shortcodes by calling the `addShortCode` method. Be careful: it is very tempting to
	 * add lots of shortcodes for conditional text, for CSS, but as far as my experience goes, it is better to
	 * maintain as few shortcodes as possible, and even use `if` only sparingly.
	 */
	public $shortcode;

	/** @var array $result */
	public $result = ['fullpath' => ''];  // Le cœur Dolibarr utilise parfois ce champ qui contient typiquement le chemin complet du PDF généré

	public $type = 'pdf'; // parce que le CommonObject attend un attribut 'type'

	protected $pageBefore = 0;
	protected $numPagesBefore = 0;

	public $footerTemplate = 'footer';

	public function __construct($db)
	{
		global $langs;
		if ($this->outputlangs === null) $outputlangs = $langs;

		// '@' because the version of TCPDF shipped with Dolibarr 10 has warnings that we can't fix
		// and I don't want to bloat up out error log
		@parent::__construct('P', 'mm', 'A4', true, 'UTF-8');

		$fontsDir = $this->getFontsDir();

		$fontNameLowercase = strtolower($this->defaultFontName);

		if (! in_array($fontNameLowercase, ['helvetica', 'hourier', 'timesroman', 'symbol', 'zapfdingbats'])) {
			foreach ($this->defaultFont as $variant => &$fontNameTCPDF) {
				// si déjà "compilée" pour TCPDF, on utilise la version prête à l'emploi
				$fontNameTCPDF = $fontNameLowercase . $fontNameTCPDF;
				$compiledFontPath = "{$fontsDir}/{$fontNameTCPDF}.php";
				if (is_readable($compiledFontPath)) {
					$this->AddFont($fontNameLowercase, $variant, $compiledFontPath);
				} elseif (is_writable(DOL_DOCUMENT_ROOT . '/includes/tecnickcom/tcpdf/fonts')) {
					// sinon, on compile (NOTE: ne jamais donner a www-data le droit d'écrire sous htdocs en prod; en local
					// uniquement)
					$variantCapitalized = ucfirst($variant);
					$fontNameTCPDF = TCPDF_FONTS::addTTFfont(
						"{$fontsDir}/{$this->defaultFontName}-{$variantCapitalized}.ttf",
						'TrueTypeUnicode',
						'',
						32
					);
				}
			}
		}


		if (getDolGlobalInt('MAIN_DISABLE_PDF_COMPRESSION')) $this->SetCompression(false);

		// note: c'est Dolibarr qui fournit $outputlangs au moment non pas de l'appel du constructeur mais
		// à l'appel de write_file, donc on doit utiliser $langs (ce qui est logique vu que sur les pages
		// d'admin où est affichée la description, on utilise la langue de l'utilisateur et non la langue
		// la langue de génération des docs, qui peut être celle du tiers par exemple)
		$this->description = $langs->transnoentities($this->tPrefix . 'description');
		$this->db = $db;

		$this->shortcode = new ShortCode();

		// Définition du short code permettant d'utiliser des flags dans les templates
		// Exemple: ${[if cond="IS_CONTRAT_BE" yes="Belgique" no="Ailleurs"]}
		// Exemple: ${[if cond="MAIN_SHOW_MACHIN" yes="--visible" no="--hidden"]}
		$this->shortcode->addShortCode('if', function ($context, $params) {
			global $conf;
			$cond = $this->shortCodeEvalCond($params['cond'] ?? '', $conf);

			$yes = $params['yes'] ?? '';
			$no = $params['no'] ?? '';
			// si le contenu du paramètre 'yes' ou 'no' est présent comme clé du tableau de substitutions, on prend
			// cette valeur; sinon, s'il existe une traduction ($langs), on prend cette traduction, sinon on prend
			// la valeur brute.
			$textIfYes = $this->TSub[$yes] ?? $this->outputlangs->trans($yes);
			$textIfNo = $this->TSub[$no] ??  $this->outputlangs->trans($no);
			return $cond ? $textIfYes : $textIfNo;
		});

		$this->shortcode->addShortCode('hideEmpty', function ($context, $params) {
			$cond = empty($this->TSub[$params['v']??'']);
			// si le contenu du paramètre 'yes' ou 'no' est présent comme clé du tableau de substitutions, on prend
			// cette valeur; sinon, s'il existe une traduction ($langs), on prend cette traduction, sinon on prend
			// la valeur brute.
			return $cond ? 'HIDDEN' : '';
		});

		/**
		 * Définition du short code permettant d'alterner des classes "odd" et "even" dans les templates
		 * (parce que TCPDF ne gère pas le CSS3).
		 *
		 * Paramètres possibles:
		 *  force = 'odd'|'even' = si définie, la variable d'état (odd ou even) sera réinitialisée à l'état forcé
		 *                         (à utiliser par exemple sur la première ligne de chaque tableau pour que sa couleur
		 *                         de fond ne dépende pas de la dernière ligne du tableau précédent)
		 *  skip-condition = si définie, la condition sera évaluée; si vraie, l'alternance entre odd et even sera
		 *                   omise pour cette fois (si on était "odd" la fois précédente, on restera "odd")
		 *  entity = 'main'|'object' = si skip-condition utilise une conf globale, on peut forcer à chercher la conf
		 *                             sur l'entité principale (1) ou sur l'entité de l'objet 
		 *                         TODO: ce comportement vient du spécifique client qui a inspiré cette classe; 
		 *                               à voir si ça peut servir ailleurs, ou à supprimer.
		 *
		 * Exemple simple: ${[oddeven]} ${[oddeven]} ${[oddeven]}
		 *         Donnera "odd even odd" (on alterne)
		 *
		 * Exemple complexe: ${[oddeven skip-condition="!MA_CONF" entity="main"]}
		 *         Imaginons que le précédent était "odd":
		 *         - Si sur l'entité principale, la conf globale MA_CONF est désactivée (le `!`), on restera sur "odd"
		 *           (on omet d'alterner car la condition est vraie)
		 *         - Sinon, on gardera le comportement standard: on passera sur "even"
		 *
		 */
		$this->shortcode->addShortCode('oddeven', function ($context, $params) {
			static $isOdd = false;
			global $conf;
			$skipCondition = $params['skip-condition'] ?? null;
			$skip = $skipCondition && $this->shortCodeEvalCond($skipCondition, $conf);
			if (!$skip) $isOdd = !$isOdd;

			// on permet de forcer le retour à odd ou even
			if (isset($params['force'])) $isOdd = $params['force'] === 'odd';
			return $isOdd ? 'odd' : 'even';
		});

		/** Définition d'un short code permettant d'utiliser une couleur depuis une conf dolibarr. */
		$this->shortcode->addShortCode('color', function ($context, $params) {
			$confName = $params['conf'] ?? '';
			if (preg_match('/^(\d+), *(\d+), *(\d+)$/', getDolGlobalString($confName), $m)) {
				[, $r, $g, $b] = $m;
				return sprintf('#%02x%02x%02x', $r, $g, $b);
			}
			return '';
		});
	}

	/**
	 * @param CommonObject|object $object  A Dolibarr object. Use CommonObject whenever possible; object is for
	 *                                     legacy codes that did not extend CommonObject
	 * @param ?Translate $outputlangs      Can be used to output the document in a language different from that
	 *                                     of the user who is generating the document.
	 * @return mixed
	 */
	abstract public function generate($object, $outputlangs = null);

	/**
	 * Effectue le début de la génération du PDF (partie commune à tous les modèles hérités de cette classe)
	 * et notamment l'appel du hook beforePDFCreation.
	 *
	 * @param CommonObject|object $object
	 * @param Translate $outputlangs
	 * @return void
	 */
	public function initGenerate($object, $outputlangs = null)
	{
		global $conf, $user, $langs, $action;
		if ($outputlangs === null) $outputlangs = $langs;
		$this->outputlangs = $outputlangs;
		$this->object = $object;

		$this->initColors();
		$this->initSubstitutionData();

		$this->setMetaData();


		$dir = $this->getObjectOutputDir();
		if (empty($dir)) {
			throw new Exception('Unable to determine output dir');
		}
		if (!is_dir($dir)) dol_mkdir($dir);


		$file = $this->getFileName();
		$this->filePath = "$dir/$file";

		$langsFile = $this->getLanguageDir().'/'.dol_sanitizeFileName(get_class($this)).'.lang';
		if (is_file($langsFile)) {
			// TODO: this is a hack; in Dolibarr, the translation domain is not supposed to contain directory separators
			//       Find better solution to load the specific translation strings for this PDF generator (maybe by overloading
			//       the Translate class? seems overkill)
			$domain = dol_sanitizeFileName(get_class($this)).'/'.dol_sanitizeFileName(get_class($this));
			$this->outputlangs->load($domain);
		}

		// hook beforePDFCreation
		$hookmanager = new HookManager($this->db);
		$hookmanager->initHooks(['pdfgeneration']);
		$parameters = ['file' => $file, 'object' => $object, 'outputlangs' => $outputlangs];
		$hookmanager->executeHooks('beforePDFCreation', $parameters, $object, $action);
	}

	/**
	 * Can be overloaded for objects with non-conventional output dirs
	 */
	public function getObjectOutputDir()
	{
		global $conf;

		// if the object has its own path getter, we use it
		if (method_exists($this->object, 'getDocumentsDir')) return $object->getDocumentsDir();

		// default to Dolibarr's standard way of finding out where documents go
		if (isset($conf->{$this->object->element}->multidir_output[$conf->entity])) {
			return $conf->{$this->object->element}->multidir_output[$conf->entity].'/'.dol_sanitizeFileName($object->ref);
		}
		return '';
	}

	public function getFileName()
	{
		if ($this->object->specimen ?? false) return 'SPECIMEN.pdf';
		return $this->getObjectName() . $this->_transNC($this->tPrefix . 'file_suffix').'.pdf';
	}

	public function getTitle()
	{
		return $this->_transNC($this->tPrefix . 'metadata_title', $this->getObjectName());
	}

	/**
	 * If the object has the method `getRef()`, we use this. If not, we use its ID. The method can be overloaded for customization.
	 */
	public function getObjectName()
	{
		return method_exists($this->object, 'getRef') ? $this->object->getRef() : $this->object->id ?? $this->object->rowid;
	}

	/**
	 * Return the path to the directory containing the html template files and the specific lang files for
	 * this PDF generator.
	 * 
	 * Example: if the subclass is called pdf_xyz_tcpdi and the output language is 'en_GB', and the subclass
	 * is part of an external module located under htdocs/custom/modulexyz, the directory will be:
	 * > /path/to/dolibarr/htdocs/custom/modulexyz/langs/en_GB/pdf_xyz_tcpdi
	 *
	 *
	 * TODO: I am not very satisfied with using sub-sub-directories of 'langs', but it has the benefit of
	 *       making it clear that the HTML templates are l18n material. Since this is still experimental,
	 *       it might change completely.
	 */
	public function getLanguageDir()
	{
		$childClassInfo = new ReflectionClass($this);

		$classDir = dirname($childClassInfo->getFileName());
		$langsDir = '';

		// go to parent directory until there is a 'langs' directory
		for ($upDepth = 1; ! is_dir($langsDir = dirname($classDir, $upDepth) . '/langs') && $upDepth < 5; $upDepth++);
		if (!is_dir($langsDir)) return ''; // TODO: throw exception, dol_syslog etc.

		// we found the 'langs' directory, now we have to identify the language subdir
		$className = dol_sanitizeFileName(get_class($this));

		// Dolibarr uses language tags based on a subset of IETF BCP 47 codes, replacing the underscore
		// with a hyphen, e.g. 'es_MX' for Spanish, Mexican variant.
		// The first 2 letters form the language tag ('es'), which identifies an ISO 639-1 language code.
		// The 2 letters after the underscore form the regional subtag ('MX'), identifying an
		// ISO 3166-1 alpha-2 country/region code.

		// TODO: define fallbacks like in Translate::setDefaultLang() and refactor
		$languagesToTry = [
			$this->outputlangs->getDefaultLang(),
			'en_US' // last resort fallback.
		];
		foreach ($languagesToTry as $languageCode) {
			// we first try the full language code
			$tplDir = $langsDir . '/' . $languageCode . '/' . $className;
			if (is_dir($tplDir)) return $tplDir;

			// if there is no direct match for the requested variant we requested, we fall back to any existing
			// directory matching the language tag (e.g. 'es_*')
			$wildcardMatches = glob($langsDir . '/' . substr($languageCode, 0, 2) . '_*' . '/' . $className);
			if ($wildcardMatches && is_dir($wildcardMatches[0])) {
				return $wildcardMatches[0];
			}
		}
		return ''; // TODO: throw exception, dol_syslog etc.
	}

	public function getFontsDir()
	{
		return $this->moduleDir.'/fonts';
	}

	/**
	 * Fonction appelée par la méthode commonGenerateDocument() du CommonObject (elle-meme appelée par generateDocument)
	 * @param ?Translate $outputlangs
	 * @param ?string $srctemplatepath
	 * @param ?bool $hidedetails
	 * @param ?bool $hidedesc
	 * @param ?bool $hideref
	 * @param ?array $moreparams
	 * @return int  Toujours true (pas de gestion d'erreur pour l'instant) TODO gérer erreurs
	 */
	public function write_file($object, $outputlangs, $srctemplatepath, $hidedetails, $hidedesc, $hideref, $moreparams)
	{
		$this->generate($object);
		return 1;
	}

	/**
	 * Affiche un tableau dont la hauteur de chaque ligne est connue à l'avance. Pas de HTML autorisé dans les cellules.
	 * Le tableau fait la pleine largeur du gabarit.
	 *
	 * @param array      $columns             paramètres des colonnes (titre, largeur, alignement)
	 *                                        [{id} => ['label' => {titre}, 'w' => {largeur}, 'align' => {L|R|C}, 'labelalign' => {L|R|C}], ...]
	 * @param array      $rows                contenu du tableau:
	 *                                        [[{id} => {texte}, {id} => {texte}, ...]]
	 * @param array|null $alternatingBGColors trame de couleur d'arrière-plan pour les lignes (permet d'alterner lignes paires / impaires)
	 * @return void
	 */
	public function writeTableWithFixedRowHeight(array $columns, array $rows, ?array $alternatingBGColors = null): void
	{
		// il doit y avoir au moins autant autant de colonnes $rows que dans $columns
		// et il doit y avoir au moins une colonne et une ligne, sinon on n'affiche rien.
		if (count($columns) === 0 || count($rows) === 0 || count($columns) > count($rows[0])) return;

		// widthCoeff: coefficient de largeur utilisé pour permettre de donner des largeurs de colonnes relatives les unes aux autres.
		// Exemple: si on a configuré les largeurs de colonnes ainsi:
		// A: 3, B: 1, C: 1, D: 2
		// alors le facteur sera 1/(3+1+1+2), ≃ 0.1428.
		// Pour un document A4 sans marges, la largeur réelle des colonnes sera:
		//  A: 21 * 6 * 0.1428 ≃ 9cm
		//  B: 21 * 1 * 0.1428 ≃ 3cm
		//  C: 21 * 1 * 0.1428 ≃ 3cm
		//  D: 21 * 2 * 0.1428 ≃ 6cm
		$widthCoeff = 1 / array_sum(array_column($columns, 'w'));
		$lastColName = array_key_last($columns);

		$this->SetDrawColor(...$this->colors['border']);

		if ($alternatingBGColors === null) {
			$alternatingBGColors = [
				[255, 255, 255],
				$this->colors['pale-bg-from-border']
			];
		}
		$nbRows = count($rows);
		$showTableHead = true; // la première fois, on affiche les en-têtes de colonnes
		for ($i = 0 ; $i < $nbRows ;) {
			$line = $rows[$i];

			$this->startTransaction();
			if ($showTableHead) {
				$this->SetFillColor(...$this->colors['border']);
				$this->SetTextColor(255, 255, 255);
				$this->SetLineWidth($this->pixelsToUnits(1));
				foreach ($columns as $colName => $colConfig) {
					$ln = $colName === $lastColName ? 1 : 0;
					$this->Cell($this->gabaritW * $widthCoeff * ($colConfig['w'] ?? 1), 0, $this->_transNC($colConfig['label'] ?? $colName ?? 'MissingLabel'), 1, $ln, $colConfig['labelalign'] ?? 'C', 1);
				}
				$this->SetTextColor(...$this->colors['font-default']);
				$showTableHead = false; // on a printé les en-têtes de colonnes, on n'a plus besoin de le faire tant que pas de saut de page
			}

			// affichage de la ligne courante (corps de tableau)
			$bordertype = ($i == $nbRows - 1) ? 'LRB' : 'LR';
			$this->SetFillColor(...$alternatingBGColors[$i % count($alternatingBGColors)]);

			// le paramètre `stretch` de Cell() permet de forcer TCPDF à réduire la taille de police si le texte ne tient pas dans la cellule:
			// c'est mieux que MultiCell, qui passerait à la ligne mais 1) comporte de petits bugs et 2) nécessiterait beaucoup plus d'algo pour
			// s'assurer que les cellules d'une ligne aient toutes la même hauteur.
			foreach ($columns as $colName => $colConfig) {
				$ln = $colName === $lastColName ? 1 : 0;
				$this->Cell($this->gabaritW * $widthCoeff * ($colConfig['w'] ?? 1), 0, $line[$colName], $bordertype, $ln, $colConfig['align'] ?? 'L', 1, '', 1);
			}

			// ——————————————————————— gestion du saut de page ——————————————————————
			if ($this->autoPageBreakDetected()) {
				// l'auto-pagebreak fait qu'on a changé de page → on rollback et on refait proprement (pour ajouter
				// l'en-tête de tableau et éviter qu'une ligne de tableau soit à cheval sur deux pages)
				$this->rollbackTransaction(true);

				// cette ligne fait office de bordure basse pour la dernière ligne de la page précédente
				$this->line($this->margins['left'], $this->GetY(), $this->w - $this->margins['right'], $this->GetY());
				$this->AddPage();
				$showTableHead = true;
				continue; // le fait de ne pas incrémenter $i fait qu'on reste sur la même ligne du tableau
			}
			$this->commitTransaction();
			$i++;
		}
	}

	// Je laisse cette ancienne méthode en commentaire afin d'avoir un exemple de ce qui est attendu par writeTableWithFixedRowHeight()
	//  /**
	//   * Retourne les données à afficher dans le tableau des matériels
	//   * @return array
	//   */
	//  public function getTableauMaterielData() {
	//      $this->object->loadMateriel();
	//
	//      $tableauMaterielData = array_map(function($mat) {
	//          return [
	//              'Materiel' => $mat->label,
	//              'MaterielQty' => $mat->qty,
	//              'MaterielCondition' => $mat->chaudOutputField('fk_condition'),
	//          ];
	//      }, array_values($this->object->materiel));
	//
	//      return $tableauMaterielData;
	//  }

	/**
	 * Mécanisme de substitution de variables dans un template (les variables sont de la forme ${ma_variable}).
	 * Les valeurs de substitution peuvent se trouver:
	 *  - dans le tableau associatif $this->TSub (prioritaire)
	 *  - dans un fichier langs (si la clé de traduction est préfixée par $this->tPrefix, ce préfixe est facultatif
	 *    dans le template. Par exemple, ${pdf_palourde_ma_cle_de_trad} peut s'écrire ${ma_cle_de_trad}
	 *
	 * Par défaut, la substitution se fait sans aucune transformation (pas de conversion d'encodage, pas d'échappement
	 * des caractères significatifs en HTML).
	 *
	 * @param string $tpl
	 * @return string
	 */
	public function sub($tpl)
	{
		// TODO: remove ambiguity between sources of substitutions (TSub, outputlangs, etc.). All simple substitutions
		//       should be in TSub. There should be a shortcode for translations and a shortcode for confs.

		// TODO 2: make recursive shortcodes possible. This probably means throwing away the regex and using a recursive
		//         mini-parser.

		$callback = function ($m) {
			$options = $m[1];
			$k = $m[2];
			// mécanisme d'échappement: si on veut faire figurer littéralement ${test}, on écrit ${[#]test}.
			//                          si on veut faire figurer littéralement ${[#]test}, on écrit ${[#][#]test} etc.
			if ($options === '#') {
				// Note: on pourrait ajouter 1000 options mais je préfère que la complexité soit dans les
				//       short codes et non ici
				return '${'.substr($k, 1).'}';
			}

			// détection et appel de short code (merci John B)
			// note: la regexp n'étant pas récursive, on ne peut actuellement pas imbriquer des short codes.
			//       si on voulait le faire, il faudrait écrire un parser, intéressant mais plus complexe
			//       et ça aurait l'inconvénient de nécessiter l'écriture de plug-ins pour les IDE (sans quoi
			//       les parsers HTML des IDE seraient en panique).
			if (preg_match('/^\[(.*)]$/', $k, $mshortcode)) {
				return $this->shortcode->doShortCode($mshortcode[0]);
			}

			// en priorité, on utilise les substitutions explicites de $TSub
			if (isset($this->TSub[$k])) {
				return $this->TSub[$k];
			}

			// ensuite, on teste les traductions ayant le même préfixe que le modèle PDF
			if (isset($this->outputlangs->tab_translate[$this->tPrefix.$k])) {
				return $this->outputlangs->trans($this->tPrefix.$k);
			}

			// puis on teste les traductions en général
			if (isset($this->outputlangs->tab_translate[$k])) {
				return $this->outputlangs->trans($k);
			}

			// et si on n'a toujours rien, on met la substitution par défaut
			// (note: en prod, il faut que ce soit une chaîne vide, mais pour les tests, on peut
			// utiliser une chaîne qui matérialise les parties variables du template).
			return $this->TSub[''] ?? '';
		};

		$tpl = preg_replace_callback('/\$\{(?:\[([^{]]+)])?([^{}]+)}/', $callback, $tpl);

		return $tpl;
	}

	/**
	 * Charge un fichier template HTML externe depuis le répertoire
	 * @param $tplFileNameWithoutExt
	 * @return false|string
	 */
	public function getHTMLFromTpl(string $tplFileNameWithoutExt): false|string
	{
		$fileName = $tplFileNameWithoutExt . '.tpl.html';
		$fullPath = $this->getLanguageDir() . "/$fileName";
		if (! file_exists($fullPath)) {
			return '<br>ERROR: file not found: ' . $fullPath;
		}
		$maxSize = 2 ** 19; // sanity check (about 0.5MB)

		return file_get_contents($fullPath, false, null, 0, $maxSize);
	}

	/**
	 * Retourne le contenu (après substitution des variables) du fichier CSS de base.
	 * Le contenu est mis en cache, ce qui évite de recharger le fichier à chaque appel.
	 */
	public function getCSS()
	{
		// la déclaration en statique agit comme un cache:
		// ça évite de recharger le fichier à chaque appel
		static $css = null;
		if (is_null($css)) $css = $this->sub($this->getHTMLFromTpl('css'));
		return $css;
	}

	/**
	 * Définit les couleurs du PDF (et potentiellement d'autres propriétés liées à la charte graphique).
	 * TODO: remplacer cette méthode plus tard, en fonction des usages. Je la laisse pour le POC.
	 *
	 * @return void
	 */
	public function initColors()
	{
		global $mysoc, $conf;
		// Couleur de police par défaut
		$this->colors['font-default'] = self::hexColorToRGBArray('#333');

		// Couleur de police pour les en-têtes de tableaux
		$this->colors['font-header-row'] = self::hexColorToRGBArray('#fff');

		// Couleur pour les bordures (foncée)
		$this->colors['border'] = self::hexColorToRGBArray('#333');

		// Couleur claire (arrières-plans) dérivée de la couleur des bordures:
		// On garde uniquement la teinte ($h) et on met en dur la saturation et la luminosité
		[$h, $s, $v] = self::rgbArrayToHSVArray($this->colors['border']);
		$this->colors['pale-bg-from-border'] = self::hsvArrayToRGBArray([$h, 3.5, 96.5]);
		$this->colors['pale-bg-white'] = self::hexColorToRGBArray('#fff');

		// Couleur d'arrière-plan pour les en-têtes de tableaux (foncée car le texte sera blanc)
		$this->colors['header-row-bg'] = $this->hexColorToRGBArray('#333');

		// Couleur claire (arrières-plans) dérivée de la couleur des en-têtes de tableaux:
		// On garde uniquement la teinte ($h) et on met en dur la saturation et la luminosité
		[$h, $s, $v] = self::rgbArrayToHSVArray($this->colors['header-row-bg']);
		$this->colors['pale-bg-from-header'] = self::hsvArrayToRGBArray([$h, 3.5, 96.5]);

		$this->colors['footer-bg'] = preg_split('/, */', getDolGlobalString('PDF_FOOTER_BACKGROUND_COLOR', '255,255,255'));
		$this->colors['footer-fg'] = preg_split('/, */', getDolGlobalString('PDF_FOOTER_TEXT_COLOR', implode(',', $this->colors['font-default'])));

		$this->logo = $conf->mycompany->dir_output . '/logos/' . getDolGlobalString('PDF_HEADER_LOGO');

		if (is_dir($this->logo) || ! is_readable($this->logo)) $this->logo = $conf->mycompany->dir_output . '/logos/' . $mysoc->logo;
	}

	/**
	 * Charge un template HTML contenant typiquement un tableau <table>, substitue les variables, ajoute le CSS et
	 * ajoute le résultat au PDF.
	 *
	 * @param string  $tplHTMLName  Nom du template à charger
	 * @param int     $incY         Espacement ajouté après le tableau
	 * @param boolean $unbreakable  Si true, le tableau ne peut pas être séparé sur deux pages. S'il ne tient pas en
	 *                              entier dans le reste de la page courante, on le fait démarrer sur la page suivante.
	 *                              (Note: s'il ne tient pas sur une page tout court, il démarrera sur la page suivante
	 *                              mais il sera quand même coupé en deux)
	 * @return void
	 */
	public function writeHTMLFromTpl($tplHTMLName, $incY = 4, $unbreakable = true)
	{
		redo:
		$pageBefore = $this->getPage();
		$tplHTML = $this->sub(preg_replace('/>\s+/', '>', $this->getHTMLFromTpl($tplHTMLName)));
		if ($unbreakable) $this->startTransaction();
		// TODO: comprendre pourquoi on a besoin de faire ces décalages de marges (+2 et -1) quand on print du HTML
		//       (bug de TCPDF ou erreur de calcul dans cette classe)
		$this->writeHTMLCell(
			$this->gabaritW + 2,
			'',
			$this->lMargin - 1,
			$this->GetY(),
			$this->getCSS() . $tplHTML,
			0,
			1
		);
		if ($this->getPage() != $pageBefore && $unbreakable) {
			$this->rollbackTransaction(true);
			$this->AddPage();
			// l'option ne peut fonctionner qu'une fois: si le tableau est trop grand pour tenir
			//tout seul sur une page, évidemment on break.
			$unbreakable = false;
			goto redo; // on pourrait très bien se passer du goto, mais celui-ci est facilement lisible
		}
		if ($unbreakable) $this->commitTransaction();
		if ($incY) $this->incY($incY);
	}

	/**
	 * Affiche le logo du client et le titre
	 * Une partie de cette méthode a été écrite spécifiquement pour un client particulier (dont le logo possède des
	 * parties blanches sur lesquelles on ajoute du texte).
	 * TODO: rendre cette méthode générique, ou bien la supprimer.
	 * 
	 * @return void
	 */
	public function ClientLogoTitle()
	{

		$title = $this->_transNC($this->tPrefix . 'title');
		$logoDimensions = dol_getImageSize($this->logo, false);
		if (empty($logoDimensions)) {
			$logoDimensions = ['height' => 50, 'width' => 50];
		}
		// Note: TCPDF::Image possède des paramètres très pratiques:
		// - fitbox: l'image gardera son ratio d'aspect et devra rentrer tout entière dans la "boîte" de largeur w et de
		//           hauteur h (c'est mieux que "resize" qui ne garde pas le ratio d'aspect)
		// - fitpage: si l'image est plus grande que la page, elle sera réduite (en gardant le ratio d'aspect) pour ne
		//           pas dépasser des marges
		// Malheureusement, on est quand même forcés de faire certains calculs si on veut pouvoir fournir un logo
		// "trop petit" et qu'on ne veut pas qu'il l'agrandisse, mais qu'on veut par contre qu'il rétrécisse un logo
		// "trop grand"
		// TODO: prévoir également une hauteur max de logo pour s'adapter à des logos plus hauts que larges, mais ce
		//       n'est pas le cas chez Client (c'est juste si jamais on copie-colle cette méthode ailleurs)
		$this->setImageScale($this->imgDPI/72); // pour que pixelsToUnits utilise la résolution mini à respecter
		$logoWidth = min($this->pixelsToUnits($logoDimensions['width']), $this->gabaritW);
		$logoHeight = $logoWidth * $logoDimensions['height'] / $logoDimensions['width'];
		$this->Image($this->logo, $this->lMargin, $this->GetY(), $logoWidth, 0, '', '', '', false, 300, '', false, false, 0, false, false, true);

		$reservedWidthL = 60; // Espace réservé pour les pixels "utiles" (= non vides) du logo à gauche
		$reservedHeightB = 5; // Espace réservé pour les pixels "utiles" (= non vides) du logo en bas

		// 'blank': zone du logo par dessus laquelle on peut écrire
		$blankWidth = $this->gabaritW - $reservedWidthL;
		$blankHeight = $logoHeight - $reservedHeightB;
		$blankOffsetX = $this->lMargin + $reservedWidthL;
		$blankOffsetY = $this->tMargin;

		$this->SetXY($blankOffsetX, $blankOffsetY + $logoHeight - $reservedHeightB);

		$this->SetFontSize(14);
		$this->Cell($blankWidth, $blankHeight, $title, 0, 0, 'R', false, '', 0, false, 'B', 'B');

		$this->SetY($this->tMargin + $logoHeight);
		$this->resetFont();
		$this->resetColor();
	}

	/**
	 * Métadonnées du PDF (titre, sujet, auteur, logiciel générateur, mots-clés…)
	 * @return void
	 */
	public function setMetaData()
	{
		global $user;
		$pfx = $this->tPrefix . 'metadata_';
		$this->SetTitle($this->getTitle());
		$this->SetSubject($this->object->element);
		$this->SetCreator('Dolibarr ' . DOL_VERSION);
		$this->SetAuthor($user->getFullName($this->outputlangs));
		$this->SetKeyWords($this->_transNC($pfx . 'keywords'));
	}

	/**
	 * Incorpore dans le PDF en cours un PDF externe.
	 *
	 * @return void
	 */
	public function includeExternalPDF($pdfFile, $fullFooter = false)
	{
		if (!is_file($pdfFile)) return;

		$oldFooterTpl = $this->footerTemplate;
		if (! $fullFooter) {
			$this->footerTemplate = null;
		}

		/* Ceci provient du module concatpdf de DoliCloud (Eldy) */
		$annexeNbPages = $this->setSourceFile($pdfFile);
		if ($annexeNbPages == 0) return;
		for ($i = 1 ; $i <= $annexeNbPages ; $i++) {
			$tplidx = $this->ImportPage($i);
			$s = $this->getTemplatesize($tplidx);
			$this->AddPage($s['h'] > $s['w'] ? 'P' : 'L');
			$this->useTemplate($tplidx);
		}
		$this->AddPage();
		$this->footerTemplate = $oldFooterTpl;
	}

	/**
	 * @return bool True si on n'est plus sur la même page qu'au moment de l'appel à startTransaction
	 *              ou si le nombre de page a changé.
	 *              False = pas de changement de page, on peut commit la transaction tranquillement.
	 */
	public function autoPageBreakDetected(): bool
	{
		return $this->getPage() > $this->pageBefore || $this->getNumPages() > $this->numPagesBefore;
	}

	/**
	 * Surcharge pour détecter les sauts de page automatiques survenus dans la transaction.
	 * @return void
	 */
	public function startTransaction(): void
	{
		$this->pageBefore = $this->getPage();

		// un bug de MultiCell() fait que MultiCell() peut ajouter une page mais revenir à la page précédente
		// (si ln==0) ce qui fait que getPage() retourne la même chose qu'avant.
		$this->numPagesBefore = $this->getNumPages();

		parent::startTransaction();
	}

	/**
	 * Surcharge du AddPage de TCPDF permettant (entre autres possibilités) de corriger des bugs
	 *
	 * @param string $orientation
	 * @param string $format
	 * @param bool   $keepmargins
	 * @param bool   $tocpage
	 * @return void
	 */
	public function AddPage($orientation = '', $format = '', $keepmargins = false, $tocpage = false)
	{
		parent::AddPage($orientation, $format, $keepmargins, $tocpage);

		// Au début, j'avais ajouté cette ligne horizontale pour débugguer, le but étant de visualiser
		// le seuil de déclenchement des sauts de page ($this->PageBreakTrigger), mais j'ai constaté
		// que le reste du PDF changeait quand je mettais cette ligne (ou n'importe quelle ligne en fait).

		// J'ai un peu cherché d'où ça pouvait venir car cet appel de fonction ne déplace pas le curseur Y
		// et ne modifie aucun des paramètres des marges (y compris $this->PageBreakTrigger). Par contre,
		// elle ajoute bien une donnée dans le buffer de sortie du PDF donc ça doit jouer un rôle, mais je
		// ne comprends pas lequel.

		// En tout cas, je laisse cette ligne (en réduisant sa largeur à 0 pour qu'elle soit invisible) car
		// grâce à elle, le seuil de saut de page est respecté (sans elle, le saut de page se déclenche au
		// ras du pied de page quand on est en mode multi-colonne malgré la configuration censée
		// l'interdire).

		// Pour moi, c'est un bug d'avoir besoin de tracer un truc invisible, mais si ça marche, tant mieux.

		//      $this->Line(0, $this->PageBreakTrigger, 0, $this->PageBreakTrigger);

		// Note: apparemment, il suffit même d'écrire un espace (qui est non significatif dans la spec du
		// format PDF¹) dans le buffer de sortie de la page et, comme par magie, l'autopagebreak fonctionne
		// sur les sections à 2 colonnes. Je n'y comprends rien mais je laisse ça : c'est plus économe
		// qu'une ligne invisible.
		//
		// ¹ cf. https://commandlinefanatic.com/cgi-bin/showarticle.cgi?article=art019
		$this->setPageBuffer($this->page, ' ', true);
	}

	/**
	 * Surcharge de la méthode Header() de TCPDF appelée automatiquement à l'ajout d'une page (y compris en
	 * autopagebreak)
	 * @return void
	 */
	public function Header()
	{
		//      parent::Header();
	}

	/**
	 * Surcharge de la méthode Footer() de TCPDF appelée automatiquement à l'ajout d'une page (y compris en
	 * autopagebreak)
	 * @return void
	 */
	public function Footer()
	{
		$pagenumtxt = $this->getAliasNumPage() . ' / ' . $this->getAliasNbPages();
		$this->SetTextColor(...$this->colors['border']);
		$this->SetFontSize(7);
		if (! empty($this->footerTemplate)) {
			$footerHTML = $this->sub($this->getHTMLFromTpl($this->footerTemplate));
			$this->writeHTMLCell($this->gabaritW, 0, $this->lMargin, $this->GetY(), $footerHTML, false, 1, false);
		}
		$this->SetX($this->original_lMargin);
		$this->incY(-3);
		$this->SetTextColor(0, 0, 0); // numéros de page en noir
		$this->Cell(0, 0, $this->getAliasRightShift() . $pagenumtxt, '', 1, 'R');
		$this->SetTextColor(...$this->colors['font-default']);
	}

	/**
	 * Fetche les objets nécessaires et retourne un tableau associatif contenant les variables communes
	 * à tous les modèles PDF.
	 *
	 * Cette fonction doit être surchargée par les classes filles.
	 *
	 * @return array
	 * @ throws Exception
	 */
	public function initSubstitutionData(): array
	{
		global $conf, $dolibarr_main_prod;

		// FETCH OBJECTS HERE, CALL FUNCTIONS TO DO CALCULATIONS.
		// THE MAIN PURPOSE IS TO HAVE ZERO CALCULATIONS DONE IN
		// THE PDF TEMPLATE.

		// some useful miscellaneous substitutions
		$checkbox = '<span style="font-family: zapfdingbats,sans-serif;">o</span>';
		$checkboxChecked = '<span style="font-family: zapfdingbats,sans-serif;">4</span>';
		$placeholderForEmptySubstitution = '';

		if (! $dolibarr_main_prod) {
			// if we are on a testing environment, we can make empty fields visible
			// TODO: make this optional: it should be possible to have the testing or staging environment
			//       generate the exact same PDFs as the production env.
			$placeholderForEmptySubstitution = '<span style="background-color: #ddd">'.str_repeat('&nbsp;', 8).'</span>';
		}
		$this->TSub = [
			// spécial: si aucune substitution trouvée, on matérialise le champ
			'' => $placeholderForEmptySubstitution,
			// spécial: on utilise la police de symboles pour faire les checkboxes
			'CHECKBOX' => $checkbox,

			// Couleurs
			'color.table_td_bg_odd' => 'rgb('.implode(',', $this->colors['pale-bg-from-border']).')',
			'color.table_td_bg_even' => 'rgb('.implode(',', $this->colors['font-header-row']).')',
			'color.table_th_bg' => 'rgb('.implode(',', $this->colors['header-row-bg']).')',
			'color.table_th_fg' => 'rgb('.implode(',', $this->colors['font-header-row']).')',
			'color.table_border' => 'rgb('.implode(',', $this->colors['border']).')',
			'color.table_td_bg' => 'rgb('.implode(',', $this->colors['pale-bg-from-border']).')',
			'color.footer_bg' => 'rgb('.implode(',', $this->colors['footer-bg']).')',
			'color.footer_fg' => 'rgb('.implode(',', $this->colors['footer-fg']).')',
		];

		// add common fields from the object
		if (isset($this->object->fields) && is_array($this->object->fields)) {
			foreach ($this->object->fields as $fieldCode => $fieldDef) {
				$this->TSub[$fieldCode] = $this->object->{$fieldCode} ?? '';
			}
		}

		return $this->TSub;
	}

	/**
	 * Surcharge de la méthode TCPDF::Output, ajoute l'option 'B64' pour $dest (pas compatible avec $this->sign).
	 *
	 * @param string $name The name of the file when saved. Used only if $dest implies saving a file.
	 * @param string $dest Cf. base method (`tcpdf.php`) for values 'I', 'D', 'F', 'S', 'FI', 'FD', 'E'.
	 *                     special use: 'B64' encodes the PDF's content as a base64 string
	 * @return string
	 * @public
	 * @since 1.0
	 * @see   TCPDF::Close()
	 * @see   TCPDF::Output()
	 */
	public function Output($name = 'doc.pdf', $dest = 'I'): string
	{
		if ($this->state < 3) {
			$this->Close();
		}
		$dest = strtoupper($dest);
		if ($dest === 'B64') return base64_encode($this->getBuffer());

		return parent::Output($name, $dest);
	}

	/**
	 * Surcharge de la méthode Close() de TCPDF, qui efface presque toutes les valeurs des propriétés de la classe
	 * (d'où la surcharge, pour éviter la destruction des variables dont on a encore besoin après Close())
	 * @return void
	 */
	public function Close()
	{
		$propNamesToSave = ['filePath']; // pour le moment, il n'y a que filePath

		// sauvegarde des propriétés importantes
		$savedProps = [];
		foreach ($propNamesToSave as $propName) $savedProps[$propName] = $this->{$propName};
		parent::Close();
		// restauration
		foreach ($savedProps as $propName => $propValue) $this->{$propName} = $propValue;
	}

	/**
	 * Surcharge de la méthode TCPDF qui ajoute juste le calcul automatique de la largeur de gabarit (= la largeur de
	 * la page moins les marges).
	 *
	 * @param float $left
	 * @param float $top
	 * @param float $right
	 * @param boolean $keepmargins
	 * @return void
	 */
	public function SetMargins($left, $top, $right = -1, $keepmargins = false)
	{
		parent::SetMargins($left, $top, $right, $keepmargins);
		$this->gabaritW = $this->w - $left - $right;
	}

	/**
	 * Définit les marges des nouvelles pages à partir d'un tableau associatif avec les clés 'left',
	 * 'top', 'right' et 'bottom'.
	 *
	 * Si une clé 'body-footer' est fournie, elle sera utilisée pour l'auto-page-break pour indiquer
	 * la marge entre le bas du contenu de la page et le haut du footer.
	 *
	 * @param array $TMargins
	 * @param float $footerHeight
	 * @return void
	 */
	public function setMarginsArray($TMargins = null, $footerHeight = null)
	{
		$TMargins ??= $this->margins;
		$footerHeight ??= $this->footerHeight;
		$this->SetMargins($TMargins['left'], $TMargins['top'], $TMargins['right'], true);
		$this->setFooterMargin($footerHeight + $TMargins['bottom']);
		if (isset($TMargins['body-footer'])) {
			$this->SetAutoPageBreak(true, $this->getFooterMargin() + $TMargins['body-footer']);
		}
	}

	/**
	 * Retourne la hauteur occupée par un bloc (matérialisé par l'appel d'une fonction $func).
	 * Utilisée notamment pour connaître dynamiquement la taille du pied de page.
	 * @param callable $func
	 * @return float How much Y space is consumed when you call $func()
	 */
	public function measureY($func)
	{
		$yStart = $this->GetY();
		$this->startTransaction();
		$this->SetAutoPageBreak(false);
		$func();
		$yEnd = $this->GetY();
		$this->rollbackTransaction(true);
		return $yEnd - $yStart;
	}

	/**
	 * Descend curseur Y de $inc unités (par défaut: millimètres)
	 * @param int $inc
	 * @return void
	 */
	public function incY($inc)
	{
		$this->SetY($this->GetY() + $inc);
	}

	/**
	 * Remet le curseur à gauche.
	 * @return void
	 */
	public function resetX()
	{
		$this->SetX($this->lMargin);
	}

	/**
	 * Remet la taille de police par défaut.
	 */
	public function resetFont()
	{
		$this->SetFont($this->defaultFont['regular'], '', 9.5);
	}

	/**
	 * Remet les couleurs par défaut (couleur d'arrière-plan, de police, des bordures)
	 * @return void
	 */
	public function resetColor()
	{
		$this->SetTextColor(...$this->colors['font-default']);
		$this->SetDrawColor(...$this->colors['border']);
		$this->SetFillColor(255, 255, 255);
	}

	/**
	 * Remet le style de ligne par défaut
	 * @return void
	 */
	public function resetLineStyle()
	{
		$this->SetLineStyle([
			'dash'  => 0,
			'width' => 0.1,
			'color' => $this->colors['border'],
		]);
	}

	public function info()
	{
		global $langs;
		return $langs->trans(get_class($this).'_description');
	}

	/**
	 * Raccourci vers transnoentities (vu qu'on ne printe pas du HTML, on ne se sert jamais de $outputlangs->trans)
	 * @param $key
	 * @param ...$args
	 * @return string
	 */
	protected function _trans($key, ...$args)
	{
		return $this->outputlangs->transnoentities($key, ...$args);
	}

	/**
	 * Raccourci vers transnoentitiesnoconv
	 * @param $key
	 * @param ...$args
	 * @return string
	 */
	protected function _transNC($key, ...$args)
	{
		return $this->outputlangs->transnoentities($key, ...$args);
	}

	/**
	 * Permet d'utiliser des conditions un peu plus complexes dans des short codes:
	 * Exemples:
	 * condition simple: cond="IS_CONTRACT_BE"
	 * condition complexe: cond="MAIN_CONF_MACHIN == 4"
	 *
	 * @param string $condName
	 * @param ?Conf $conf      Objet Conf à utiliser: par défaut, utilisera l'objet global mais les short codes peuvent définir un
	 *                         paramètre `conf="main"` ou `conf="object"` pour pouvoir sélectionner l'entité d'où viendra
	 *                         la conf.
	 * @return bool
	 */
	public function shortCodeEvalCond(string $condName, ?Conf $conf = null): bool
	{
		if (is_null($conf)) {
			global $conf;
		}
		$operator = $compareValue = $negate = null;
		if (preg_match('/^(!?) *(\w+) *([=><!&|]*) *(-?\w*)$/', $condName, $m)) {
			[, $negate, $condName, $operator, $compareValue] = $m;
		}
		// $condName peut être le nom d'une clé dans TSub ou d'une conf.
		$condVal = $this->TSub[$condName] ?? $conf->global->{$condName} ?? null;

		if ($operator === '==') $result = $condVal == $compareValue;
		elseif ($operator === '>=') $result = $condVal >= $compareValue;
		elseif ($operator === '<=') $result = $condVal <= $compareValue;
		elseif ($operator === '!=') $result = $condVal != $compareValue;
		elseif ($operator === '&') $result = $condVal & $compareValue;
		elseif ($operator === '&&') $result = $condVal && $compareValue;
		elseif ($operator === '|') $result = $condVal | $compareValue;
		elseif ($operator === '||') $result = $condVal || $compareValue;
		else $result = $condVal;

		$result = boolval($result);
		if ($negate) $result = ! $result;
		return $result;
	}

	/**
	 *  Return list of active generation modules
	 *
	 * @param DoliDB $db                Database handler
	 * @param int    $maxfilenamelength Max length of value to show
	 * @return    array                        List of templates
	 */
	public static function liste_modeles(DoliDB $db, int $maxfilenamelength = 0)
	{
		include_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';

		return getListOfModels($db, self::objectElement, $maxfilenamelength);
	}

    /**
     * Converts RGB color values to HSV
     * @param array $rgb Array of [red, green, blue] values (0-255)
     * @return array Array of [hue (0-360), saturation (0-100), value (0-100)]
     */
    public static function rgbArrayToHSVArray(array $rgb): array
    {
        // Normalize RGB values to 0-1 range
        $r = $rgb[0] / 255;
        $g = $rgb[1] / 255;
        $b = $rgb[2] / 255;

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $diff = $max - $min;

        $h = 0;
        $s = 0;
        $v = $max;

        // Calculate Hue
        if (intval($diff) !== 0) {
            if ($max === $r) {
                $h = 60 * (fmod(($g - $b) / $diff, 6));
            } elseif ($max === $g) {
                $h = 60 * (($b - $r) / $diff + 2);
            } else { // $max === $b
                $h = 60 * (($r - $g) / $diff + 4);
            }
        }

        // Ensure hue is positive
        if ($h < 0) {
            $h += 360;
        }

        // Calculate Saturation
        $s = $max === 0 ? 0 : ($diff / $max);

        // Convert to percentage values
        $s = $s * 100;
        $v = $v * 100;

        return [round($h), round($s), round($v)];
    }

    /**
     * Converts HSV color values to RGB
     * @param array $hsv Array of [hue (0-360), saturation (0-100), value (0-100)]
     * @return array Array of [red, green, blue] values (0-255)
     */
    public static function hsvArrayToRGBArray(array $hsv): array
    {
        $h = $hsv[0];
        $s = $hsv[1] / 100;
        $v = $hsv[2] / 100;

        $c = $v * $s;
        $x = $c * (1 - abs(fmod($h / 60, 2) - 1));
        $m = $v - $c;

        $r = 0;
        $g = 0;
        $b = 0;

        if ($h >= 0 && $h < 60) {
            list($r, $g, $b) = [$c, $x, 0];
        } elseif ($h >= 60 && $h < 120) {
            list($r, $g, $b) = [$x, $c, 0];
        } elseif ($h >= 120 && $h < 180) {
            list($r, $g, $b) = [0, $c, $x];
        } elseif ($h >= 180 && $h < 240) {
            list($r, $g, $b) = [0, $x, $c];
        } elseif ($h >= 240 && $h < 300) {
            list($r, $g, $b) = [$x, 0, $c];
        } else {
            list($r, $g, $b) = [$c, 0, $x];
        }

        return [
            round(($r + $m) * 255),
            round(($g + $m) * 255),
            round(($b + $m) * 255)
        ];
    }

    /**
     * Converts a hex color string to RGB array
     * @param string $hex Hex color code (e.g., "#ff0000" or "#f00")
     * @return array Array of [red, green, blue] values (0-255)
     * @throws InvalidArgumentException if hex string format is invalid
     */
    public static function hexColorToRGBArray(string $hex): array
    {
        // Remove # if present
        $hex = ltrim($hex, '#');
        
        // Validate hex string
        if (!preg_match('/^[0-9A-Fa-f]{3}(?:[0-9A-Fa-f]{3})?$/', $hex)) {
            throw new InvalidArgumentException('Invalid hex color format');
        }

        // Convert 3-digit hex to 6-digit
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        // Convert to RGB values
        return [
            hexdec(substr($hex, 0, 2)), // Red
            hexdec(substr($hex, 2, 2)), // Green
            hexdec(substr($hex, 4, 2))  // Blue
        ];
    }
}