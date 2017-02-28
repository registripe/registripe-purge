<?php
/**
 * Tests that the registration purge task works as expected.
 *
 * @package registripe
 * @subpackage tests
 */
class EventRegistrationPurgeTaskTest extends SapphireTest {

	public static $fixture_file = 'fixtures/EventRegistrationPurgeTaskTest.yml';

	/**
	 * @covers EventRegistrationPurgeTask::purgeUnsubmittedRegistrations
	 */
	public function testPurgeTaskDeletesUnsubmittedRegistrations() {
		$task         = new EventRegistrationPurgeTask();
		$unsubmitted1 = $this->objFromFixture('EventRegistration', 'unsubmitted_1');
		$unsubmitted2 = $this->objFromFixture('EventRegistration', 'unsubmitted_2');

		$update = 'UPDATE "EventRegistration" SET "Created" = \'%s\' WHERE "ID" = %d';

		$this->assertEquals(2, $this->countUnsubmitted(), "Two unsubmitted records in db");

		ob_start();

		$task->run(null);
		$this->assertEquals(2, $this->countUnsubmitted(), "No change");

		// Update the first registration to be 10 minutes ago, it shouldn't get
		// deleted.
		DB::query(sprintf(
			$update,
			date('Y-m-d H:i:s', sfTime::subtract(time(), 15, sfTime::MINUTE)),
			$unsubmitted1->ID));

		$task->run(null);
		$this->assertEquals(2, $this->countUnsubmitted());

		// Now update it to 20 minutes ago, one should be deleted.
		DB::query(sprintf(
			$update,
			date('Y-m-d H:i:s', sfTime::subtract(time(), 20, sfTime::MINUTE)),
			$unsubmitted1->ID));

		$task->run(null);
		$this->assertEquals(1, $this->countUnsubmitted());

		// Now push the second one way into the past.
		$created = sfTime::subtract(time(), 1000, sfTime::DAY);
		DB::query(sprintf(
			$update,
			date('Y-m-d H:i:s', $created),
			$unsubmitted2->ID));

		$task->run(null);
		$this->assertEquals(0, $this->countUnsubmitted());

		// Ensure the confirmed event is still there.
		$this->assertEquals(1, $this->countValid());

		ob_end_clean();
	}

	/**
	 * @covers EventRegistrationPurgeTask::purgeUnconfirmedRegistrations
	 */
	public function testPurgeTaskCancelsUnconfirmedRegistrations() {
		$task         = new EventRegistrationPurgeTask();
		$unconfirmed1 = $this->objFromFixture('EventRegistration', 'unconfirmed_1');
		$unconfirmed2 = $this->objFromFixture('EventRegistration', 'unconfirmed_2');

		ob_start();

		//nothing should be canceled by default
		$task->run(null);
		$this->assertEquals(0, $this->countCancelled());

		// Update the first task to be just shy of six hours less than the
		// created date.
		$created = strtotime($unconfirmed1->Created);
		$created = sfTime::subtract($created, 5, sfTime::HOUR);
		$unconfirmed1->Created = date('Y-m-d H:i:s', $created);
		$unconfirmed1->write();
		$task->run(null);
		$this->assertEquals(0, $this->countCancelled());

		// Now push it beyond six hours
		$created = sfTime::subtract($created, 3, sfTime::HOUR);
		$unconfirmed1->Created = date('Y-m-d H:i:s', $created);
		$unconfirmed1->write();
		$task->run(null);
		$this->assertEquals(1, $this->countCancelled());

		// Now push the second one way back, and check it's also canceled
		$created = sfTime::subtract(time(), 1000, sfTime::DAY);
		$unconfirmed2->Created = date('Y-m-d H:i:s', $created);
		$unconfirmed2->write();
		$task->run(null);

		$this->assertEquals(2, $this->countCancelled());

		// Ensure the confirmed event is still there.
		$confirmed = EventRegistration::get()->filter("Status","Valid");
		$this->assertEquals(1, $confirmed->count());

		ob_end_clean();
	}

	protected function countUnsubmitted() {
		return $this->countQuery('Unsubmitted');
	}

	protected function countCancelled() {
		return $this->countQuery('Cancelled');
	}

	protected function countValid() {
		return $this->countQuery('Valid');
	}

	protected function countQuery($status) {
		return DB::query(
			"SELECT COUNT(*) FROM \"EventRegistration\" WHERE \"Status\" = '" . $status . "'"
		)->value();
	}

}