<?php

namespace SmtpBuddy\Tests\Unit;

use SmtpBuddy\Log\Entry;
use SmtpBuddy\Tests\TestCase;

final class EntryTest extends TestCase {

	public function test_decodes_row(): void {
		$entry = new Entry(
			array(
				'id'           => '7',
				'status'       => 'sent',
				'content_type' => 'text/html',
				'body'         => '<p>Hi</p>',
				'headers'      => json_encode(
					array(
						'to' => array( array( 'email' => 'a@example.com', 'name' => 'A' ), array( 'name' => 'missing email' ) ),
						'cc' => 'not-a-list',
					)
				),
				'attachments'  => json_encode( array( array( 'name' => 'x.pdf', 'stored' => false ) ) ),
				'parent_id'    => '3',
			)
		);

		$this->assertSame( 7, $entry->id );
		$this->assertTrue( $entry->is_html() );
		$this->assertTrue( $entry->has_content() );
		$this->assertSame( array( array( 'email' => 'a@example.com', 'name' => 'A' ) ), $entry->addresses( 'to' ) );
		$this->assertSame( array(), $entry->addresses( 'cc' ) );
		$this->assertSame( 'x.pdf', $entry->attachments[0]['name'] );
		$this->assertSame( 3, $entry->parent_id );
	}

	public function test_metadata_only_row_has_no_content(): void {
		$entry = new Entry( array( 'id' => 1, 'body' => null, 'headers' => '{broken' ) );

		$this->assertFalse( $entry->has_content() );
		$this->assertSame( array(), $entry->headers );
	}
}
