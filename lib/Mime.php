<?php
/**
 * @package   Wamailer
 * @author    Bobe <wascripts@phpcodeur.net>
 * @link      http://phpcodeur.net/wascripts/wamailer/
 * @copyright 2002-2016 Aurélien Maille
 * @license   http://www.gnu.org/copyleft/lesser.html  GNU Lesser General Public License
 *
 * @see RFC 5322 - Internet Message Format
 * @see RFC 2045 - Multipurpose Internet Mail Extensions (MIME) Part One: Format of Internet Message Bodies
 * @see RFC 2046 - Multipurpose Internet Mail Extensions (MIME) Part Two: Media Types
 * @see RFC 2047 - Multipurpose Internet Mail Extensions (MIME) Part Three: Message Header Extensions for Non-ASCII Text
 * @see RFC 2049 - Multipurpose Internet Mail Extensions (MIME) Part Five: Conformance Criteria and Examples
 * @see RFC 2231 - MIME Parameter Value and Encoded Word Extensions: Character Sets, Languages, and Continuations
 * @see RFC 4021 - Registration of Mail and MIME Header Fields
 * @see RFC 2392 - Content-ID and Message-ID Uniform Resource Locators
 * @see RFC 2183 - Communicating Presentation Information in Internet Messages: The Content-Disposition Header Field
 * @see RFC 2387 - The MIME Multipart/Related Content-type
 * @see RFC 2557 - MIME Encapsulation of Aggregate Documents, such as HTML (MHTML)
 */

namespace Wamailer;

use Exception;

class Mime
{
	/**
	 * @param string $filename
	 *
	 * @return string
	 */
	public static function getType($filename)
	{
		if (!is_readable($filename)) {
			trigger_error("Cannot read file '$filename'", E_USER_WARNING);
			return null;
		}

		if (class_exists('\\finfo')) {
			$info = new \finfo(FILEINFO_MIME_TYPE);
			$type = $info->file($filename);
		}
		else if (function_exists('mime_content_type')) {
			$type = mime_content_type($filename);
		}
		else if (function_exists('exec')) {
			$type = exec(sprintf('file -b --mime-type %s 2>/dev/null',
				escapeshellarg($filename)),
				$null,
				$result
			);

			if ($result !== 0 || !strpos($type, '/')) {
				$type = '';
			}
		}

		if (empty($type)) {
			$type = 'application/octet-stream';
		}

		return trim($type);
	}
}
