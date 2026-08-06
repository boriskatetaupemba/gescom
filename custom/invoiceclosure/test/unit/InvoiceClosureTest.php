<?php
/* Copyright (C) 2026 InvoiceClosure module
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    custom/invoiceclosure/test/unit/InvoiceClosureTest.php
 * \ingroup invoiceclosure
 * \brief   PHPUnit tests of the InvoiceClosure business class.
 *
 * Run from a development environment with PHP CLI and PHPUnit:
 *   cd htdocs/custom/invoiceclosure
 *   phpunit test/unit/InvoiceClosureTest.php
 *
 * The tests create their own third party and invoices, and roll everything
 * back through direct cleanup in tearDownAfterClass(). The module
 * InvoiceClosure MUST be enabled before running the tests.
 */

global $conf, $user, $langs, $db;

// Bootstrap Dolibarr (test located in custom/invoiceclosure/test/unit/)
require_once dirname(__FILE__).'/../../../../master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
dol_include_once('/invoiceclosure/class/invoiceclosure.class.php');

use PHPUnit\Framework\TestCase;

if (empty($user->id)) {
	print "Load permissions for admin user nb 1\n";
	$user->fetch(1);
	$user->loadRights();
}
$conf->global->MAIN_DISABLE_ALL_MAILS = 1;


/**
 * Class InvoiceClosureTest
 */
class InvoiceClosureTest extends TestCase
{
	/**
	 * @var DoliDB Database handler
	 */
	private static $db;

	/**
	 * @var User Admin user with all module rights
	 */
	private static $fulluser;

	/**
	 * @var User User without invoiceclosure rights
	 */
	private static $nopermuser;

	/**
	 * @var int Id of the test third party
	 */
	private static $socid;

	/**
	 * @var int[] Ids of invoices created by the tests (for cleanup)
	 */
	private static $invoiceIds = array();

	/**
	 * Create shared fixtures: a third party and two users.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void
	{
		global $conf, $user, $langs, $db;
		self::$db = $db;

		self::$db->begin(); // Not committed: everything created here stays in the transaction until we commit explicitly

		// The module business logic requires these constants; force defaults
		$conf->global->INVOICECLOSURE_REQUIRE_ZERO_REMAIN = '1';
		$conf->global->INVOICECLOSURE_LOCK_CLOSED_INVOICES = '1';
		$conf->global->INVOICECLOSURE_ALLOW_REOPEN = '1';
		$conf->global->INVOICECLOSURE_REQUIRE_CLOSE_NOTE = '0';
		$conf->global->INVOICECLOSURE_REQUIRE_REOPEN_NOTE = '0';
		$conf->global->INVOICECLOSURE_CREATE_AGENDA_EVENT = '0'; // keep tests independent from agenda module

		// Full-rights user = current admin
		self::$fulluser = $user;
		if (empty(self::$fulluser->rights->invoiceclosure)) {
			self::$fulluser->loadRights('invoiceclosure');
		}

		// User without module rights
		self::$nopermuser = new User(self::$db);
		self::$nopermuser->login = 'icl_noperm_'.dol_print_date(dol_now(), '%Y%m%d%H%M%S');
		self::$nopermuser->lastname = 'InvoiceClosureNoPerm';
		self::$nopermuser->entity = $conf->entity;
		$res = self::$nopermuser->create(self::$fulluser);
		if ($res < 0) {
			die("Failed to create test user: ".self::$nopermuser->error."\n");
		}
		// Give invoice read right only (no invoiceclosure rights at all)
		self::$nopermuser->addrights(0, 'facture');
		self::$nopermuser->loadRights('', 1);

		// Third party
		$soc = new Societe(self::$db);
		$soc->name = 'ICL TEST COMPANY '.dol_print_date(dol_now(), '%Y%m%d%H%M%S');
		$soc->client = 1;
		$soc->entity = $conf->entity;
		$res = $soc->create(self::$fulluser);
		if ($res <= 0) {
			die("Failed to create test company: ".$soc->error."\n");
		}
		self::$socid = (int) $soc->id;

		self::$db->commit();
	}

	/**
	 * Remove everything the tests created.
	 *
	 * @return void
	 */
	public static function tearDownAfterClass(): void
	{
		global $user;

		self::$db->begin();
		foreach (self::$invoiceIds as $invid) {
			// Remove module rows first (FK), keep nothing behind
			self::$db->query("DELETE FROM ".MAIN_DB_PREFIX."invoiceclosure WHERE fk_facture = ".((int) $invid));
			self::$db->query("DELETE FROM ".MAIN_DB_PREFIX."invoiceclosure_log WHERE fk_facture = ".((int) $invid));
			self::$db->query("DELETE FROM ".MAIN_DB_PREFIX."paiement_facture WHERE fk_facture = ".((int) $invid));
			self::$db->query("DELETE FROM ".MAIN_DB_PREFIX."facturedet WHERE fk_facture = ".((int) $invid));
			self::$db->query("DELETE FROM ".MAIN_DB_PREFIX."facture WHERE rowid = ".((int) $invid));
		}
		if (self::$socid > 0) {
			self::$db->query("DELETE FROM ".MAIN_DB_PREFIX."societe WHERE rowid = ".((int) self::$socid));
		}
		if (self::$nopermuser instanceof User && self::$nopermuser->id > 0) {
			self::$nopermuser->delete($user);
		}
		self::$db->commit();
	}

	/**
	 * Create a draft invoice with one line of 100 (tax free for simplicity).
	 *
	 * @return Facture Draft invoice
	 */
	private function createDraftInvoice()
	{
		global $conf, $langs;

		$invoice = new Facture(self::$db);
		$invoice->socid = self::$socid;
		$invoice->type = Facture::TYPE_STANDARD;
		$invoice->date = dol_now();
		$invoice->entity = $conf->entity;
		$res = $invoice->create(self::$fulluser);
		$this->assertGreaterThan(0, $res, 'Invoice creation failed: '.$invoice->error);
		self::$invoiceIds[] = (int) $invoice->id;

		$res = $invoice->addline('Test line', 100, 1, 0);
		$this->assertGreaterThan(0, $res, 'Add line failed: '.$invoice->error);
		$invoice->fetch($invoice->id);
		return $invoice;
	}

	/**
	 * Validate an invoice.
	 *
	 * @param  Facture $invoice Invoice
	 * @return Facture          Refreshed invoice
	 */
	private function validateInvoice(Facture $invoice)
	{
		$res = $invoice->validate(self::$fulluser);
		$this->assertGreaterThan(0, $res, 'Validation failed: '.$invoice->error);
		$invoice->fetch($invoice->id);
		return $invoice;
	}

	/**
	 * Pay an invoice in full and classify it paid.
	 *
	 * @param  Facture $invoice Invoice
	 * @return Facture          Refreshed invoice (status Paid)
	 */
	private function payInvoice(Facture $invoice)
	{
		global $conf, $langs;

		$paymentTypeId = (int) dol_getIdFromCode(self::$db, 'LIQ', 'c_paiement', 'code', 'id', 1);
		if ($paymentTypeId <= 0) {
			$paymentTypeId = (int) dol_getIdFromCode(self::$db, 'VIR', 'c_paiement', 'code', 'id', 1);
		}
		$this->assertGreaterThan(0, $paymentTypeId, 'No usable payment mode found in dictionary');

		$payment = new Paiement(self::$db);
		$payment->datepaye = dol_now();
		$payment->amounts = array($invoice->id => (float) $invoice->total_ttc);
		$payment->paiementid = $paymentTypeId;
		$payment->num_payment = 'ICLTEST';
		$res = $payment->create(self::$fulluser, 1);
		$this->assertGreaterThan(0, $res, 'Payment failed: '.$payment->error);

		// Paiement::create(user, 1) may already have classified the invoice paid
		$invoice->fetch($invoice->id);
		if (empty($invoice->paye)) {
			$res = $invoice->setPaid(self::$fulluser);
			$this->assertGreaterThan(0, $res, 'setPaid failed: '.$invoice->error);
			$invoice->fetch($invoice->id);
		}
		$this->assertEquals(Facture::STATUS_CLOSED, (int) $invoice->statut, 'Invoice should be at standard status Paid (2)');
		$this->assertEquals(1, (int) $invoice->paye, 'Invoice should be flagged fully paid');
		return $invoice;
	}

	/**
	 * Scenario 1: closing a fully paid invoice succeeds.
	 *
	 * @return Facture Closed invoice, reused by dependent tests
	 */
	public function testCloseFullyPaidInvoice()
	{
		$invoice = $this->createDraftInvoice();
		$invoice = $this->validateInvoice($invoice);
		$invoice = $this->payInvoice($invoice);

		$statusBefore = (int) $invoice->statut;

		$closure = new InvoiceClosure(self::$db);
		$res = $closure->close($invoice, self::$fulluser, 'Clôture de test', 'OTHER', '');
		$this->assertEquals(1, $res, 'close() should return 1: '.$closure->error);
		$this->assertEquals(InvoiceClosure::STATUS_CLOSED, (int) $closure->closure_status);
		$this->assertNotEmpty($closure->date_closure);
		$this->assertEquals(self::$fulluser->id, (int) $closure->fk_user_closure);
		$this->assertEquals('Clôture de test', $closure->closure_note);

		// Scenario 22: the standard status is unchanged
		$invoice->fetch($invoice->id);
		$this->assertEquals($statusBefore, (int) $invoice->statut, 'llx_facture.fk_statut must remain unchanged');
		$this->assertEquals(1, (int) $invoice->paye, 'paye flag must remain unchanged');

		return $invoice;
	}

	/**
	 * Scenario 2: a draft invoice cannot be closed.
	 *
	 * @return void
	 */
	public function testCloseDraftInvoiceRefused()
	{
		$invoice = $this->createDraftInvoice();

		$closure = new InvoiceClosure(self::$db);
		$res = $closure->close($invoice, self::$fulluser, '', 'OTHER', '');
		$this->assertLessThan(0, $res, 'Closing a draft invoice must fail');
		$this->assertEquals('NOT_PAID', $closure->errorCode);
	}

	/**
	 * Scenario 3: a validated but unpaid invoice cannot be closed.
	 *
	 * @return void
	 */
	public function testCloseUnpaidInvoiceRefused()
	{
		$invoice = $this->createDraftInvoice();
		$invoice = $this->validateInvoice($invoice);

		$closure = new InvoiceClosure(self::$db);
		$res = $closure->close($invoice, self::$fulluser, '', 'OTHER', '');
		$this->assertLessThan(0, $res, 'Closing an unpaid invoice must fail');
		$this->assertEquals('NOT_PAID', $closure->errorCode);
	}

	/**
	 * Scenario 4: an abandoned invoice cannot be closed.
	 *
	 * @return void
	 */
	public function testCloseAbandonedInvoiceRefused()
	{
		$invoice = $this->createDraftInvoice();
		$invoice = $this->validateInvoice($invoice);
		$res = $invoice->setCanceled(self::$fulluser, Facture::CLOSECODE_ABANDONED, 'test abandon');
		$this->assertGreaterThan(0, $res, 'setCanceled failed: '.$invoice->error);
		$invoice->fetch($invoice->id);

		$closure = new InvoiceClosure(self::$db);
		$res = $closure->close($invoice, self::$fulluser, '', 'OTHER', '');
		$this->assertLessThan(0, $res, 'Closing an abandoned invoice must fail');
		$this->assertEquals('ABANDONED', $closure->errorCode);
	}

	/**
	 * Scenario 5: closing without the module permission is refused.
	 *
	 * @return void
	 */
	public function testCloseWithoutPermissionRefused()
	{
		$invoice = $this->createDraftInvoice();
		$invoice = $this->validateInvoice($invoice);
		$invoice = $this->payInvoice($invoice);

		$closure = new InvoiceClosure(self::$db);
		$res = $closure->close($invoice, self::$nopermuser, '', 'OTHER', '');
		$this->assertLessThan(0, $res, 'Closing without permission must fail');
		$this->assertEquals('PERMISSION_DENIED', $closure->errorCode);
	}

	/**
	 * Scenario 6: an invoice of another entity is refused.
	 *
	 * @return void
	 */
	public function testCloseOtherEntityRefused()
	{
		$invoice = $this->createDraftInvoice();
		$invoice = $this->validateInvoice($invoice);
		$invoice = $this->payInvoice($invoice);

		// Simulate an invoice living in another entity
		$invoice->entity = 999999;

		$closure = new InvoiceClosure(self::$db);
		$res = $closure->close($invoice, self::$fulluser, '', 'OTHER', '');
		$this->assertLessThan(0, $res, 'Closing an invoice of another entity must fail');
		$this->assertEquals('ENTITY_MISMATCH', $closure->errorCode);
	}

	/**
	 * Scenarios 9/10/17 of the API contract at business level: idempotency.
	 *
	 * @return void
	 */
	public function testCloseIdempotency()
	{
		$invoice = $this->createDraftInvoice();
		$invoice = $this->validateInvoice($invoice);
		$invoice = $this->payInvoice($invoice);

		$closure = new InvoiceClosure(self::$db);

		// First closure with a request id
		$res = $closure->close($invoice, self::$fulluser, 'first', 'API', 'CLOSE-TEST-0001');
		$this->assertEquals(1, $res, 'First close should return 1: '.$closure->error);
		$firstDate = $closure->date_closure;

		$history = $closure->getHistory($invoice->id, (int) $invoice->entity);
		$this->assertCount(1, $history);

		// Scenario 9: same request id replayed -> 2, no new history, date unchanged
		$closure2 = new InvoiceClosure(self::$db);
		$res = $closure2->close($invoice, self::$fulluser, 'replay', 'API', 'CLOSE-TEST-0001');
		$this->assertEquals(2, $res, 'Replay with same request_id should return 2');
		$this->assertEquals($firstDate, $closure2->date_closure, 'Original closure date must be preserved');
		$history = $closure2->getHistory($invoice->id, (int) $invoice->entity);
		$this->assertCount(1, $history, 'No new history line on replay');

		// Scenario 10: different request id on an already closed invoice -> 2 (idempotent, documented)
		$closure3 = new InvoiceClosure(self::$db);
		$res = $closure3->close($invoice, self::$fulluser, 'other request', 'API', 'CLOSE-TEST-0002');
		$this->assertEquals(2, $res, 'Different request_id on closed invoice should return 2');
		$history = $closure3->getHistory($invoice->id, (int) $invoice->entity);
		$this->assertCount(1, $history, 'Still no new history line');
	}

	/**
	 * Scenarios 11/12/13: reopening and full history after close/reopen/close.
	 *
	 * @return void
	 */
	public function testReopenAndHistory()
	{
		global $conf;

		$invoice = $this->createDraftInvoice();
		$invoice = $this->validateInvoice($invoice);
		$invoice = $this->payInvoice($invoice);

		$closure = new InvoiceClosure(self::$db);
		$res = $closure->close($invoice, self::$fulluser, 'close 1', 'OTHER', '');
		$this->assertEquals(1, $res);

		// Reopen
		$res = $closure->reopen($invoice, self::$fulluser, 'reopen 1', 'OTHER', '');
		$this->assertEquals(1, $res, 'reopen() should return 1: '.$closure->error);
		$this->assertEquals(InvoiceClosure::STATUS_NOT_CLOSED, (int) $closure->closure_status);
		$this->assertNotEmpty($closure->date_reopen);

		// The standard status stays Paid after reopening
		$invoice->fetch($invoice->id);
		$this->assertEquals(Facture::STATUS_CLOSED, (int) $invoice->statut, 'Standard status must remain Paid after reopening');
		$this->assertEquals(1, (int) $invoice->paye);

		// Reopening when not closed fails
		$res = $closure->reopen($invoice, self::$fulluser, 'reopen again', 'OTHER', '');
		$this->assertLessThan(0, $res);
		$this->assertEquals('NOT_CLOSED', $closure->errorCode);

		// Close again
		$res = $closure->close($invoice, self::$fulluser, 'close 2', 'OTHER', '');
		$this->assertEquals(1, $res, 'Second close should succeed: '.$closure->error);

		// Scenario 13: full history preserved (CLOSE, REOPEN, CLOSE)
		$history = $closure->getHistory($invoice->id, (int) $invoice->entity);
		$this->assertCount(3, $history);
		$this->assertEquals('CLOSE', $history[0]['action_code']);
		$this->assertEquals('REOPEN', $history[1]['action_code']);
		$this->assertEquals('CLOSE', $history[2]['action_code']);
	}

	/**
	 * Reopen disabled by configuration.
	 *
	 * @return void
	 */
	public function testReopenDisabled()
	{
		global $conf;

		$invoice = $this->createDraftInvoice();
		$invoice = $this->validateInvoice($invoice);
		$invoice = $this->payInvoice($invoice);

		$closure = new InvoiceClosure(self::$db);
		$res = $closure->close($invoice, self::$fulluser, '', 'OTHER', '');
		$this->assertEquals(1, $res);

		$conf->global->INVOICECLOSURE_ALLOW_REOPEN = '0';
		$res = $closure->reopen($invoice, self::$fulluser, 'note', 'OTHER', '');
		$conf->global->INVOICECLOSURE_ALLOW_REOPEN = '1';

		$this->assertLessThan(0, $res);
		$this->assertEquals('REOPEN_DISABLED', $closure->errorCode);
	}

	/**
	 * Mandatory note configuration is enforced.
	 *
	 * @return void
	 */
	public function testMandatoryNotes()
	{
		global $conf;

		$invoice = $this->createDraftInvoice();
		$invoice = $this->validateInvoice($invoice);
		$invoice = $this->payInvoice($invoice);

		$closure = new InvoiceClosure(self::$db);

		$conf->global->INVOICECLOSURE_REQUIRE_CLOSE_NOTE = '1';
		$res = $closure->close($invoice, self::$fulluser, '', 'OTHER', '');
		$conf->global->INVOICECLOSURE_REQUIRE_CLOSE_NOTE = '0';
		$this->assertLessThan(0, $res);
		$this->assertEquals('NOTE_REQUIRED', $closure->errorCode);

		$res = $closure->close($invoice, self::$fulluser, 'with note', 'OTHER', '');
		$this->assertEquals(1, $res);

		$conf->global->INVOICECLOSURE_REQUIRE_REOPEN_NOTE = '1';
		$res = $closure->reopen($invoice, self::$fulluser, '', 'OTHER', '');
		$conf->global->INVOICECLOSURE_REQUIRE_REOPEN_NOTE = '0';
		$this->assertLessThan(0, $res);
		$this->assertEquals('NOTE_REQUIRED', $closure->errorCode);
	}

	/**
	 * Scenario 17/21: lock of closed invoices, rollback of blocked operations,
	 * and scenario 23 (payments not modified).
	 *
	 * @return void
	 */
	public function testLockBlocksModifications()
	{
		global $conf;

		$invoice = $this->createDraftInvoice();
		$invoice = $this->validateInvoice($invoice);
		$invoice = $this->payInvoice($invoice);

		$closure = new InvoiceClosure(self::$db);
		$res = $closure->close($invoice, self::$fulluser, 'locked', 'OTHER', '');
		$this->assertEquals(1, $res);

		// Users without the force right cannot set the invoice back to unpaid:
		// the trigger returns <0 and Facture::setUnpaid() rolls back.
		$hadforce = !empty(self::$fulluser->rights->invoiceclosure->force);
		if ($hadforce) {
			self::$fulluser->rights->invoiceclosure->force = 0;
		}
		$res = $invoice->setUnpaid(self::$fulluser);
		if ($hadforce) {
			self::$fulluser->rights->invoiceclosure->force = 1;
		}
		$this->assertLessThan(0, $res, 'setUnpaid on a locked closed invoice must be blocked by the trigger');

		// Scenario 21/22/23: after the rollback, status and payments are intact
		$invoice->fetch($invoice->id);
		$this->assertEquals(Facture::STATUS_CLOSED, (int) $invoice->statut, 'Status intact after blocked operation');
		$this->assertEquals(1, (int) $invoice->paye);
		$remain = (float) price2num($invoice->getRemainToPay(0), 'MT');
		$this->assertEquals(0.0, $remain, 'Payments must be untouched');

		// With lock disabled the same operation goes through (then restore state)
		$conf->global->INVOICECLOSURE_LOCK_CLOSED_INVOICES = '0';
		$res = $invoice->setUnpaid(self::$fulluser);
		$conf->global->INVOICECLOSURE_LOCK_CLOSED_INVOICES = '1';
		$this->assertGreaterThan(0, $res, 'setUnpaid must work when lock is disabled');
		$invoice->fetch($invoice->id);
		$res = $invoice->setPaid(self::$fulluser);
		$this->assertGreaterThan(0, $res);
	}

	/**
	 * getClosureStatus() and isClosed() reflect reality.
	 *
	 * @return void
	 */
	public function testStatusReaders()
	{
		$invoice = $this->createDraftInvoice();
		$invoice = $this->validateInvoice($invoice);
		$invoice = $this->payInvoice($invoice);

		$closure = new InvoiceClosure(self::$db);
		$this->assertEquals(InvoiceClosure::STATUS_NOT_CLOSED, $closure->getClosureStatus($invoice->id, (int) $invoice->entity));
		$this->assertFalse($closure->isClosed($invoice->id, (int) $invoice->entity));

		$res = $closure->close($invoice, self::$fulluser, '', 'OTHER', '');
		$this->assertEquals(1, $res);

		$this->assertEquals(InvoiceClosure::STATUS_CLOSED, $closure->getClosureStatus($invoice->id, (int) $invoice->entity));
		$this->assertTrue($closure->isClosed($invoice->id, (int) $invoice->entity));
	}
}
