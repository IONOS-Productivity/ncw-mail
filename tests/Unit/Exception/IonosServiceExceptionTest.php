<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Tests\Unit\Exception;

use OCA\Mail\Exception\IonosServiceException;
use Test\TestCase;

class IonosServiceExceptionTest extends TestCase {
	public function testConstructorWithNoData(): void {
		$exception = new IonosServiceException('Test message', 500);

		$this->assertEquals('Test message', $exception->getMessage());
		$this->assertEquals(500, $exception->getCode());
		$this->assertEquals([], $exception->getData());
	}

	public function testConstructorWithData(): void {
		$data = [
			'errorCode' => 'DUPLICATE_EMAIL',
			'email' => 'test@example.com',
			'userId' => 'user123',
		];

		$exception = new IonosServiceException('Duplicate email', 409, null, $data);

		$this->assertEquals('Duplicate email', $exception->getMessage());
		$this->assertEquals(409, $exception->getCode());
		$this->assertEquals($data, $exception->getData());
	}

	public function testConstructorWithPreviousException(): void {
		$previous = new \Exception('Original error');
		$data = ['context' => 'test'];

		$exception = new IonosServiceException('Wrapped error', 500, $previous, $data);

		$this->assertEquals('Wrapped error', $exception->getMessage());
		$this->assertEquals(500, $exception->getCode());
		$this->assertEquals($previous, $exception->getPrevious());
		$this->assertEquals($data, $exception->getData());
	}

	public function testGetDataReturnsEmptyArrayByDefault(): void {
		$exception = new IonosServiceException();

		$this->assertEquals([], $exception->getData());
	}

	public function testGetDataPreservesComplexData(): void {
		$data = [
			'errorCode' => 'VALIDATION_ERROR',
			'fields' => ['email', 'password'],
			'metadata' => [
				'timestamp' => 1234567890,
				'requestId' => 'req-123',
			],
		];

		$exception = new IonosServiceException('Validation failed', 400, null, $data);

		$this->assertEquals($data, $exception->getData());
		$this->assertIsArray($exception->getData()['fields']);
		$this->assertIsArray($exception->getData()['metadata']);
	}
}
