<?php
/**
 * "Cómo se arregla": para las reglas más comunes, qué cambió en PHP y un ejemplo de código antes
 * y después. Se muestra dentro de cada grupo de hallazgos del detalle. Las reglas sin entrada no
 * muestran nada (el mensaje de PHPCompatibility ya dice la alternativa cuando existe).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PHPReady_Fixes {

	/** Catálogo: expresión sobre la regla (sin "PHPCompatibility.") → texto, antes, después. */
	private static function catalog() {
		return array(
			'#^FunctionDeclarations\.RemovedImplicitlyNullableParam#' => array(
				__( 'PHP 8.4: a typed parameter whose default value is null must say so in its type. Add a "?" before the type.', 'upgravo' ),
				"function save( array \$data = null ) {",
				"function save( ?array \$data = null ) {",
			),
			'#^Namespaces\.ReservedNames\.#' => array(
				__( 'The namespace name belongs to a PHP extension. It only fails on servers where that extension is installed. It usually comes from a bundled library: updating the library (or prefixing its namespace) fixes it.', 'upgravo' ),
				"namespace Http\\Promise;",
				"namespace MyPlugin\\Vendor\\Http\\Promise;",
			),
			'#^FunctionNameRestrictions\.ReservedFunctionNames\.#' => array(
				__( 'Names that start with two underscores are reserved for PHP itself. It works today; it would break only if a future PHP version adds a function with the same name. Renaming it removes the risk.', 'upgravo' ),
				"function __sortHeaders( \$a, \$b ) {",
				"function sort_headers( \$a, \$b ) {",
			),
			'#^ParameterValues\.RemovedTriggerErrorLevel#' => array(
				__( 'PHP 8.4: E_USER_ERROR is deprecated in trigger_error(). Throw an exception instead.', 'upgravo' ),
				"trigger_error( 'Invalid config', E_USER_ERROR );",
				"throw new \\RuntimeException( 'Invalid config' );",
			),
			'#^ParameterValues\.RemovedProprietaryCSVEscaping#' => array(
				__( 'PHP 8.4: the CSV functions want the $escape argument written explicitly, because its default will change. Passing the current default keeps the same behaviour.', 'upgravo' ),
				"\$row = str_getcsv( \$line );\nfputcsv( \$handle, \$fields );",
				"\$row = str_getcsv( \$line, ',', '\"', '\\\\' );\nfputcsv( \$handle, \$fields, ',', '\"', '\\\\' );",
			),
			'#^FunctionUse\.RemovedFunctions\.(imap_|pspell_|oci_|ocifetchinto|ociparse)#' => array(
				__( 'PHP 8.4 removed this extension from PHP itself (IMAP, Pspell and OCI8 moved to PECL). It only keeps working if the host installs the PECL extension. In the plugin, check that the function exists before calling it, so the site shows a message instead of a fatal error.', 'upgravo' ),
				"\$result = %fn%( ... );",
				"if ( ! function_exists( '%fn%' ) ) {\n\treturn new WP_Error( 'no_ext', 'Missing PHP extension.' );\n}\n\$result = %fn%( ... );",
			),
			'#^FunctionUse\.RemovedFunctions\.(curl_close|curl_share_close|xml_parser_free|imagedestroy|finfo_close)Deprecated#' => array(
				__( 'PHP 8.5: this function has done nothing since PHP 8.0 (the resource is freed automatically) and is now deprecated. Delete the line.', 'upgravo' ),
				"%fn%( \$handle );",
				"// Delete the line (or use unset( \$handle ); if you need to free it now).",
			),
			'#^FunctionUse\.RemovedFunctions\.mysqli_pingDeprecated#' => array(
				__( 'PHP 8.4: mysqli_ping() is deprecated. Run the query and reconnect if it fails.', 'upgravo' ),
				"if ( ! mysqli_ping( \$db ) ) {\n\t\$db = connect();\n}",
				"try {\n\t\$db->query( 'SELECT 1' );\n} catch ( mysqli_sql_exception \$e ) {\n\t\$db = connect();\n}",
			),
			'#^FunctionUse\.RemovedFunctions\.xml_set_objectDeprecated#' => array(
				__( 'PHP 8.4: xml_set_object() is deprecated. Pass full callbacks to the handlers instead.', 'upgravo' ),
				"xml_set_object( \$parser, \$this );\nxml_set_element_handler( \$parser, 'start', 'end' );",
				"xml_set_element_handler( \$parser, array( \$this, 'start' ), array( \$this, 'end' ) );",
			),
			'#^FunctionUse\.RemovedFunctions\.utf8_(en|de)codeDeprecated#' => array(
				__( 'PHP 8.2: utf8_encode() and utf8_decode() are deprecated. Use mb_convert_encoding().', 'upgravo' ),
				"\$text = utf8_encode( \$latin1 );",
				"\$text = mb_convert_encoding( \$latin1, 'UTF-8', 'ISO-8859-1' );",
			),
			'#^FunctionUse\.RemovedFunctions\.each#' => array(
				__( 'each() no longer exists since PHP 8.0: calling it is a fatal error. Use foreach.', 'upgravo' ),
				"while ( list( \$key, \$value ) = each( \$items ) ) {",
				"foreach ( \$items as \$key => \$value ) {",
			),
			'#^FunctionUse\.RemovedFunctions\.create_function#' => array(
				__( 'create_function() no longer exists since PHP 8.0: calling it is a fatal error. Use an anonymous function.', 'upgravo' ),
				"\$fn = create_function( '\$a', 'return \$a * 2;' );",
				"\$fn = function ( \$a ) {\n\treturn \$a * 2;\n};",
			),
			'#^(Extensions\.RemovedExtensions\.mysql_|FunctionUse\.RemovedFunctions\.mysql_)#' => array(
				__( 'The old mysql_* functions do not exist since PHP 7.0. In WordPress, use $wpdb.', 'upgravo' ),
				"\$rows = mysql_query( \"SELECT * FROM t WHERE id = \$id\" );",
				"global \$wpdb;\n\$rows = \$wpdb->get_results( \$wpdb->prepare( \"SELECT * FROM t WHERE id = %d\", \$id ) );",
			),
			'#^(Extensions\.RemovedExtensions\.mcrypt|FunctionUse\.RemovedFunctions\.mcrypt_)#' => array(
				__( 'mcrypt does not exist since PHP 7.2. Use the OpenSSL functions (data encrypted with mcrypt needs a migration step).', 'upgravo' ),
				"\$out = mcrypt_encrypt( MCRYPT_RIJNDAEL_128, \$key, \$data, MCRYPT_MODE_CBC, \$iv );",
				"\$out = openssl_encrypt( \$data, 'aes-128-cbc', \$key, OPENSSL_RAW_DATA, \$iv );",
			),
			'#^(FunctionUse\.RemovedFunctions\.mhash|Constants\.RemovedConstants\.mhash_)#' => array(
				__( 'The mhash functions are deprecated since PHP 8.1 and the MHASH_* constants since PHP 8.5. Use hash().', 'upgravo' ),
				"\$sum = mhash( MHASH_MD5, \$data );",
				"\$sum = hash( 'md5', \$data, true );",
			),
			'#^FunctionUse\.RemovedFunctions\.strftimeDeprecated#' => array(
				__( 'PHP 8.1: strftime() is deprecated. In WordPress, use wp_date().', 'upgravo' ),
				"echo strftime( '%d/%m/%Y', \$ts );",
				"echo wp_date( 'd/m/Y', \$ts );",
			),
			'#^FunctionUse\.RemovedFunctions\.money_format#' => array(
				__( 'money_format() no longer exists since PHP 8.0. Use NumberFormatter (intl) or number_format().', 'upgravo' ),
				"echo money_format( '%i', \$price );",
				"echo number_format( \$price, 2 );",
			),
			'#^TextStrings\.RemovedDollarBraceStringEmbeds#' => array(
				__( 'PHP 8.2: "${var}" inside a string is deprecated. Write "{$var}".', 'upgravo' ),
				"echo \"Hello \${name}\";",
				"echo \"Hello {\$name}\";",
			),
			'#^ParameterValues\.RemovedImplodeFlexibleParamOrder#' => array(
				__( 'Since PHP 8.0 implode() only accepts the separator first: the reverse order is a fatal error.', 'upgravo' ),
				"echo implode( \$tags, ', ' );",
				"echo implode( ', ', \$tags );",
			),
			'#^Operators\.RemovedTernaryAssociativity#' => array(
				__( 'Since PHP 8.0, chained ternaries without parentheses are a fatal error. Add parentheses.', 'upgravo' ),
				"\$x = \$a ? 'a' : \$b ? 'b' : 'c';",
				"\$x = \$a ? 'a' : ( \$b ? 'b' : 'c' );",
			),
			'#^Constants\.RemovedConstants\.e_strict#' => array(
				__( 'PHP 8.4: E_STRICT is deprecated (it has not been used since PHP 8.0). Remove it.', 'upgravo' ),
				"error_reporting( E_ALL | E_STRICT );",
				"error_reporting( E_ALL );",
			),
			'#^TypeCasts\.RemovedTypeCasts\.#' => array(
				__( 'PHP 8.5: the long cast names are deprecated. Use the short ones: (bool), (int), (float), (string).', 'upgravo' ),
				"\$on = (boolean) \$value;\n\$n = (integer) \$value;",
				"\$on = (bool) \$value;\n\$n = (int) \$value;",
			),
			'#^LanguageConstructs\.RemovedLanguageConstructs\.t_backtick#' => array(
				__( 'PHP 8.5: the backtick operator is deprecated. Use shell_exec().', 'upgravo' ),
				"\$out = `ls -la`;",
				"\$out = shell_exec( 'ls -la' );",
			),
			'#^FunctionUse\.RemovedFunctions\.spl_object_hashDeprecated#' => array(
				__( 'PHP 8.6: spl_object_hash() is deprecated. Use spl_object_id(). It returns a number instead of a 32-character text, so check any code that stores the result or compares it with a string.', 'upgravo' ),
				"\$key = spl_object_hash( \$object );",
				"\$key = spl_object_id( \$object );",
			),
			'#^FunctionUse\.RemovedFunctions\.is_doubleDeprecated#' => array(
				__( 'PHP 8.6: is_double() is an old alias and is now deprecated. Use is_float(), which does exactly the same.', 'upgravo' ),
				"if ( is_double( \$value ) ) {",
				"if ( is_float( \$value ) ) {",
			),
			'#^FunctionUse\.RemovedFunctions\.(is_integer|is_long)Deprecated#' => array(
				__( 'PHP 8.6: this function is an old alias and is now deprecated. Use is_int(), which does exactly the same.', 'upgravo' ),
				"if ( %fn%( \$value ) ) {",
				"if ( is_int( \$value ) ) {",
			),
			'#^FunctionUse\.RemovedFunctions\.doublevalDeprecated#' => array(
				__( 'PHP 8.6: doubleval() is an old alias and is now deprecated. Use floatval(), which does exactly the same.', 'upgravo' ),
				"\$price = doubleval( \$raw );",
				"\$price = floatval( \$raw );",
			),
			'#^FunctionUse\.RemovedFunctions\.(mb_ereg|mb_eregi|mb_split|mb_regex_)#' => array(
				__( 'PHP 8.6: the mb_ereg family of functions is deprecated. Use the preg_ functions with the "u" modifier, which handle UTF-8 text. mb_regex_encoding() and mb_regex_set_options() can simply be deleted.', 'upgravo' ),
				"\$clean = mb_ereg_replace( '[0-9]+', '#', \$text );\n\$parts = mb_split( ',', \$list );",
				"\$clean = preg_replace( '/[0-9]+/u', '#', \$text );\n\$parts = preg_split( '/,/u', \$list );",
			),
			'#^FunctionUse\.RemovedFunctions\.strcollDeprecated#' => array(
				__( 'PHP 8.6: strcoll() is deprecated because it depends on the server locale. Use Collator from the intl extension, with the language you need.', 'upgravo' ),
				"usort( \$names, 'strcoll' );",
				"\$collator = new Collator( 'es_ES' );\nusort( \$names, array( \$collator, 'compare' ) );",
			),
			'#^FunctionDeclarations\.RemovedReturnYieldInConstructDestruct#' => array(
				__( 'PHP 8.6: returning a value from __construct() or __destruct() is deprecated. The value was always ignored, so leave the return without a value.', 'upgravo' ),
				"public function __construct( \$id ) {\n\tif ( ! \$id ) {\n\t\treturn false;\n\t}\n}",
				"public function __construct( \$id ) {\n\tif ( ! \$id ) {\n\t\treturn;\n\t}\n}",
			),
			'#^ControlStructures\.RemovedReturnFromFinally#' => array(
				__( 'PHP 8.6: a return inside finally is deprecated. It silently replaces the value returned by try and swallows exceptions. Move the return out of the finally block.', 'upgravo' ),
				"try {\n\t\$result = run();\n} finally {\n\tcleanup();\n\treturn \$result;\n}",
				"try {\n\t\$result = run();\n} finally {\n\tcleanup();\n}\nreturn \$result;",
			),
			'#^ParameterValues\.RemovedIsASubclassOfAllowStringFalse#' => array(
				__( 'PHP 8.6: calling is_a() or is_subclass_of() with a class name as text while the third argument is false is deprecated. With text it always returned false, so check that the value is an object first: the result stays the same.', 'upgravo' ),
				"if ( is_a( \$value, 'WC_Product', false ) ) {",
				"if ( is_object( \$value ) && is_a( \$value, 'WC_Product' ) ) {",
			),
			'#^ControlStructures\.RemovedTerminatingCaseWithSemicolon#' => array(
				__( 'PHP 8.5: ending a case of a switch with a semicolon is deprecated. Use a colon.', 'upgravo' ),
				"case 'draft';\ndefault;",
				"case 'draft':\ndefault:",
			),
			'#^ParameterValues\.RemovedGetClassNoArgs#' => array(
				__( 'PHP 8.3: get_class() without an argument is deprecated. Use self::class or static::class.', 'upgravo' ),
				"\$name = get_class();",
				"\$name = self::class;",
			),
			'#^PHPReady\.Php82\.DynamicProperty#' => array(
				__( 'PHP 8.2: creating a property that the class does not declare is deprecated. Declare it in the class.', 'upgravo' ),
				"class Cart {\n\tpublic function __construct() {\n\t\t\$this->items = array();\n\t}\n}",
				"class Cart {\n\tpublic \$items;\n\tpublic function __construct() {\n\t\t\$this->items = array();\n\t}\n}",
			),
			'#^PHPReady\.Php82\.RelativeCallable#' => array(
				__( 'PHP 8.2: callables written as a string like "self::method" are deprecated. Use an array with the class name.', 'upgravo' ),
				"array_map( 'self::clean', \$items );",
				"array_map( array( self::class, 'clean' ), \$items );",
			),
		);
	}

	/** Ayuda para una regla, o null si no hay. */
	public static function for_source( $source ) {
		$rule = preg_replace( '/^PHPCompatibility\./', '', (string) $source );
		// Nombre de la función de la regla ("FunctionUse.RemovedFunctions.pspell_newRemoved" → pspell_new),
		// para que el ejemplo muestre la función que de verdad aparece.
		$fn = preg_match( '/RemovedFunctions\.([a-z0-9_]+?)(Deprecated)?(Removed)?$/i', $rule, $m ) ? $m[1] : 'function_name';
		foreach ( self::catalog() as $rx => $fix ) {
			if ( preg_match( $rx, $rule ) ) {
				return array(
					'text'   => $fix[0],
					'before' => str_replace( '%fn%', $fn, $fix[1] ),
					'after'  => str_replace( '%fn%', $fn, $fix[2] ),
				);
			}
		}
		return null;
	}

	/** Bloque HTML dentro de un grupo del detalle. */
	public static function render( $source ) {
		$fix = self::for_source( $source );
		if ( ! $fix ) {
			return;
		}
		$pre = 'margin:2px 0 0;padding:6px 8px;background:#fff;border:1px solid #dcdcde;font-size:12px;white-space:pre;overflow:auto;tab-size:4';
		?>
		<div style="margin:2px 0 10px 24px;max-width:960px">
			<p style="margin:4px 0"><strong><?php esc_html_e( 'How to fix:', 'upgravo' ); ?></strong> <?php echo esc_html( $fix['text'] ); ?></p>
			<div style="display:flex;gap:12px;flex-wrap:wrap">
				<div style="flex:1;min-width:260px"><small style="color:#b32d2e"><?php esc_html_e( 'Before', 'upgravo' ); ?></small><pre style="<?php echo esc_attr( $pre ); ?>"><?php echo esc_html( $fix['before'] ); ?></pre></div>
				<div style="flex:1;min-width:260px"><small style="color:#00843d"><?php esc_html_e( 'After', 'upgravo' ); ?></small><pre style="<?php echo esc_attr( $pre ); ?>"><?php echo esc_html( $fix['after'] ); ?></pre></div>
			</div>
		</div>
		<?php
	}
}
