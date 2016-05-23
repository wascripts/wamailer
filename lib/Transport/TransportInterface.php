<?php
/**
 * @package   Wamailer
 * @author    Bobe <wascripts@phpcodeur.net>
 * @link      http://phpcodeur.net/wascripts/wamailer/
 * @copyright 2002-2016 Aurélien Maille
 * @license   http://www.gnu.org/copyleft/lesser.html  GNU Lesser General Public License
 */

namespace Wamailer\Transport;

interface TransportInterface
{
	/**
	 * @param array $opts
	 */
	public function __construct(array $opts = []);

	/**
	 * Définition des options supplémentaires pour ce transport.
	 *
	 * @param array $opts
	 *
	 * @return array
	 */
	public function options(array $opts = []);

	/**
	 * Traitement/envoi d’un email.
	 *
	 * @param Email $email
	 */
	public function send(\Wamailer\Email $email);

	/**
	 * Indique la fin des envois.
	 * Utile pour refermer une connexion réseau ou terminer un processus.
	 */
	public function close();
}
