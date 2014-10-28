<?php
/**
 * @package   Wamailer
 * @author    Bobe <wascripts@phpcodeur.net>
 * @link      http://phpcodeur.net/wascripts/wamailer/
 * @copyright 2002-2014 Aurélien Maille
 * @license   http://www.gnu.org/copyleft/lesser.html  GNU Lesser General Public License
 */

/**
 * Vérifie si une adresse email N’EST PAS valide (domaine et compte).
 * Ceci est différent d’une vérification de validité.
 * Le serveur SMTP peut très bien répondre par un 250 ok pour une adresse
 * email non existante, les erreurs d’adressage étant traitées ultérieurement
 * au niveau du serveur POP.
 *
 * Appels possibles à cette fonction :
 *
 * $result = validateMailBox('username@domain.tld');
 * $result = validateMailBox('username@domain.tld', $results);
 * $result = validateMailBox(array('username1@domain.tld',
 *     'username2@domain.tld', 'username@otherdomain.tld'), $results);
 *
 * @param mixed $emailList     Adresse email complète ou tableau d’adresses email
 * @param array $return_errors Passage par référence. Retour d’erreur sous
 *                             forme de tableau :
 *                             array('address1@domain.tld' => 'msg error...',
 *                                   'address2@domain.tld' => 'msg error...')
 *
 * @return boolean
 */
function validateMailbox($emailList, &$return_errors = null)
{
	if (!class_exists('Mailer_SMTP')) {
		require dirname(__FILE__) . '/smtp.class.php';
	}

	if (!is_array($emailList)) {
		$emailList = array($emailList);
	}
	else {
		$emailList = array_unique($emailList);
	}

	$domainList = $return_errors = array();

	foreach ($emailList as $email) {
		if (strpos($email, '@')) {
			list($mailbox, $domain) = explode('@', $email);

			if (!isset($domainList[$domain])) {
				$domainList[$domain] = array();
			}

			$domainList[$domain][] = $mailbox;
		}
		else {
			$return_errors[$email] = 'Invalid syntax';
		}
	}

	foreach ($domainList as $domain => $mailboxList) {
		$mxhosts = array();
		if (function_exists('getmxrr')) {
			$result = getmxrr($domain, $hosts, $weight);

			for ($i = 0, $m = count($hosts); $i < $m; $i++) {
				$mxhosts[] = array($weight[$i], $hosts[$i]);
			}
		}
		else {
			exec(sprintf('nslookup -type=mx %s', escapeshellcmd($domain)), $lines);

			$regexp = '/^' . preg_quote($domain) . '\s+(?:(?i)MX\s+)?'
				. '(preference\s*=\s*([0-9]+),\s*)?'
				. 'mail\s+exchanger\s*=\s*(?(1)|([0-9]+)\s+)([^ ]+?)\.?$/';

			foreach ($lines as $value) {
				if (preg_match($regexp, $value, $match)) {
					$mxhosts[] = array(
						($match[3] === '' ? $match[2] : $match[3]),
						$match[4]
					);
				}
			}

			$result = (count($mxhosts) > 0);
		}

		if (!$result) {
			$mxhosts[] = array(0, $domain);
		}

		array_multisort($mxhosts);

		$smtp = new Mailer_SMTP();

		foreach ($mxhosts as $record) {
			try {
				$smtp->connect($record[1]);
				if ($smtp->from('wamailer@' . $domain)) {
					foreach ($mailboxList as $mailbox) {
						$email = $mailbox . '@' . $domain;

						if (!$smtp->to($email, true)) {
							$return_errors[$email] = $smtp->responseData;
						}
					}
				}

				$smtp->quit();
				break;
			}

			//
			// Code temporaire à remplacer
			//
			catch (Exception $e) {
				if (!$result) {
					foreach ($mailboxList as $mailbox) {
						$return_errors[$mailbox . '@' . $domain] = $e->getMessage();
					}
					break;
				}
			}
		}
	}

	return (count($return_errors) == 0);
}
