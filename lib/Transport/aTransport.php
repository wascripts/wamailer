<?php
/**
 * @package   Wamailer
 * @author    Bobe <wascripts@phpcodeur.net>
 * @link      http://phpcodeur.net/wascripts/wamailer/
 * @copyright 2002-2015 Aurélien Maille
 * @license   http://www.gnu.org/copyleft/lesser.html  GNU Lesser General Public License
 */

namespace Wamailer\Transport;

use Exception;
use Wamailer\Mailer;
use Wamailer\Email;

abstract class aTransport implements iTransport
{
	/**
	 * Tableau d’options pour ce transport.
	 *
	 * @var array
	 */
	protected $opts = array();

	/**
	 * @param array $opts
	 */
	public function __construct(array $opts = array())
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
	public function options(array $opts = array())
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
