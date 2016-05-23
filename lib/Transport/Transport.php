<?php
/**
 * @package   Wamailer
 * @author    Bobe <wascripts@phpcodeur.net>
 * @link      http://phpcodeur.net/wascripts/wamailer/
 * @copyright 2002-2016 Aurélien Maille
 * @license   http://www.gnu.org/copyleft/lesser.html  GNU Lesser General Public License
 */

namespace Wamailer\Transport;

use Exception;
use Wamailer\Mailer;
use Wamailer\Email;
use Wamailer\Tools\Dkim;

abstract class Transport implements TransportInterface
{
	/**
	 * Tableau d’options pour ce transport.
	 *
	 * @var array
	 */
	protected $opts = [];

	/**
	 * @var Dkim
	 * @status unstable
	 */
	protected $dkim = null;

	/**
	 * @param array $opts
	 */
	public function __construct(array $opts = [])
	{
		$this->options($opts);
	}

	/**
	 * Définition des options supplémentaires pour ce transport.
	 *
	 * @param array $opts
	 *
	 * @return array
	 */
	public function options(array $opts = [])
	{
		$this->opts = array_replace_recursive($this->opts, $opts);

		return $this->opts;
	}

	/**
	 * Prépare le message avant son envoi.
	 * En particulier, on s’assure que les en-têtes 'Date' et 'From',
	 * obligatoires, sont bien présents.
	 *
	 * @see RFC 5322#3.6 - Field Definitions
	 *
	 * @param Email $email
	 *
	 * @throws Exception
	 * @return Email
	 */
	protected function prepareMessage(Email $email)
	{
		$email = clone $email;

		if (!$email->headers->get('From')) {
			throw new Exception("The message must have a 'From' header to be RFC compliant.");
		}

		if (!$email->hasRecipients()) {
			throw new Exception("No recipient address given");
		}

		if (!$email->headers->get('Date')) {
			$email->headers->set('Date', date(DATE_RFC2822));
		}

		if (!empty(Mailer::$signature)) {
			$email->headers->set('X-Mailer', sprintf(Mailer::$signature, Mailer::VERSION));
		}

		if (!$email->headers->get('To') && !$email->headers->get('Cc')) {
			// Tous les destinataires sont en copie cachée. On ajoute quand
			// même un en-tête To pour le mentionner.
			$email->headers->set('To', 'undisclosed-recipients:;');
		}

		/**
		 * L’en-tête Return-Path ne devrait être ajouté que par le dernier
		 * serveur SMTP de la chaîne de transmission et non pas par le MUA.
		 *
		 * @see RFC 5321#4.4 - Trace Information
		 */
		$email->headers->remove('Return-Path');

		if (!empty($this->opts['dkim'])) {
			if (!$this->dkim) {
				// Conversion déjà faite dans \Wamailer\Mime\Part
				$this->opts['dkim']['fixcrlf'] = false;
				$this->dkim = new Dkim($this->opts['dkim']);
			}

			list($headers, $message) = explode("\r\n\r\n", $email->__toString(), 2);

			if ($dkim_header = $this->dkim->sign($headers, $message)) {
				list($hdr_name, $hdr_value) = explode(':', $dkim_header, 2);
				$email->headers->add($hdr_name, trim($hdr_value));
			}
		}

		return $email;
	}

	/**
	 * Indique la fin des envois.
	 * Utile pour refermer une connexion réseau ou terminer un processus.
	 */
	public function close()
	{
		// Do nothing
	}
}
