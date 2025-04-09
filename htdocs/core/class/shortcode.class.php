<?php
/**
 * © 2024 John Botella
* SPDX-License-Identifier: GPL-3.0-or-later
* This file is part of Dolibarr ERP / CRM
*/

// from https://github.com/anacronox/shortcode
class ShortCode
{

	public $shortCodeManifest = [];

	/**
	 * Registers a short code and the corresponding callback.
	 *
	 * @param string   $name       the shorcode name
	 * @param callback $callback
	 * @param array    $dependency an array of files needed for inclusion
	 */
	public function addShortCode(string $name, callable $callback, array $dependency = []): void
	{
		// TODO : check name, $callback and $dependency are valid
		// TODO : for perfomances check is callable in doShortCode

		$shortCode = new stdClass();
		$shortCode->name = $name;
		$shortCode->callback = $callback;

		if (! is_array($dependency)) {
			$shortCode->dependency = [$dependency];
		} else {
			$shortCode->dependency = $dependency;
		}

		$this->shortCodeManifest[$name] = $shortCode;
	}

	/**
	 * Parses a template string to detect short codes (inside square brackets) and substitute them for the return value of the
	 * corresponding registered callback.
	 *
	 * @param string $string  The template string which we want to parse to substitute short codes for their computed value
	 * @param string $context the context of execution of shortcode, usefull to display diferent king of output according to context
	 *                        exemple of context : "html:adminpanel", "html:frontpanel", "nohtml:youradditionalcontext" ...
	 * @return string
	 */
	public function doShortCode(string $string, string $context = 'default'): string
	{
		return preg_replace_callback('#\[(.*?)\]#', function ($matches) use ($context) {
			$literalStr = $matches[0];
			if (! self::parseShortCode($matches[1], $shortCodeName, $shortCodeParams)) {
				// syntaxe short code non reconnue = on renvoie littéralement ce qu'on a trouvé
				return $literalStr;
			}

			$shortCode = $this->shortCodeManifest[$shortCodeName] ?? null;

			if (! $shortCode || ! is_callable($shortCode->callback)) {
				return $literalStr;
			}

			if (! empty($shortCode->dependency)) {
				foreach ($shortCode->dependency as $dependency) {
					require_once $dependency;
				}
			}

			return call_user_func($shortCode->callback, $context, $shortCodeParams);
		}, $string);
	}

	/**
	 * Un parser non récursif pour le nom et les paramètres d'un short code. Le but est d'autoriser
	 * des paramètres sans valeur (la valeur sera automatiquement une chaîne vide), ce qu'un parser
	 * XML strict ne permet pas (SimpleXMLElement met un warning) et les valeurs non quotées (auquel
	 * cas la valeur ne doit contenir que des caractères autorisés dans un identifiant).
	 *
	 * @param string  $string Chaîne du type: myShortCode param1="test numero 1" param2 param3='simples "quotes"'
	 * @param ?string &$shortCodeName
	 * @param ?array  &$shortCodeParams
	 * @return bool  False si erreur de syntaxe, True si parsé jusqu'au bout.
	 */
	public static function parseShortCode(string $string, ?string &$shortCodeName = '', ?array &$shortCodeParams = []): bool
	{
		$ret = [];
		$TEnclosureChars = ['"', "'"];
		if (! preg_match('/^(\w+)(.*)$/', $string, $m)) return false;
		$shortCodeName = $m[1];
		$string = $m[2];

		$shortCodeParams = [];

		while (preg_match('/^[\s|\n]+([\w-]+)(=?)(.*)$/', $string, $m)) {
			$paramName = $m[1];
			$paramValue = '';
			$string = $m[3];
			if ($m[2] && $m[3]) {
				// cas entre quotes (doubles ou simple): on prend la valeur jusqu'à la prochaine quote du même type.
				if (in_array($m[3][0], $TEnclosureChars)) {
					$enclosureChar = $m[3][0]; // quote ou double quote
					// on recherche l'enclosure correspondante (pas de mécanisme d'échappement en XML) sans compter le premier caractère
					$pos = strpos($string, $enclosureChar, 1);
					if ($pos === false) return false; // syntaxe invalide (quotes non équilibrées)
					if ($pos !== false) {
						$paramValue = substr($string, 1, $pos - 1);
						$string = substr($string, $pos + 1);
					}
				}
				// cas sans quotes: on prend la valeur jusqu'au premier espace trouvé et on n'autorise que les caractères de la plage \w.
				elseif (preg_match('/^(\S+)(.*)$/', $string, $m)) {
					$paramValue = $m[1];
					if (! preg_match('/^\w+$/', $paramValue)) return false;
					$string = $m[2];
				}
			}
			$shortCodeParams[$paramName] = $paramValue;
		}

		return true;
	}
}
