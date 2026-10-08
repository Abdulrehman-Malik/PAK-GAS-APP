<?php
declare(strict_types=1);
namespace Tests\Unit;
use App\Services\CylinderStatus;
use PHPUnit\Framework\TestCase;
final class CylinderStatusTest extends TestCase {
 public function testFilled():void{$this->assertSame('FILLED',(new CylinderStatus())->resolve('15.000','15.000','SHOP'));}
 public function testPartial():void{$this->assertSame('PARTIAL',(new CylinderStatus())->resolve('7.500','15.000','SHOP'));}
 public function testEmpty():void{$this->assertSame('EMPTY',(new CylinderStatus())->resolve('0.000','15.000','SHOP'));}
 public function testIssuedOverridesFillState():void{$this->assertSame('ISSUED',(new CylinderStatus())->resolve('15.000','15.000','CUSTOMER'));}
 public function testSoldOverridesFillState():void{$this->assertSame('SOLD',(new CylinderStatus())->resolve('15.000','15.000','SOLD'));}
}