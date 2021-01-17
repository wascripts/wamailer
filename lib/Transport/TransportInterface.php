<?php
/**
 * @package   Wamailer
 * @author    Bobe <wascripts@webnaute.net>
 * @link      http://dev.webnaute.net/wamailer/
 * @copyright 2002-2021 Aurélien Maille
 * @license   https://www.gnu.org/licenses/lgpl.html  GNU Lesser General Public License
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
