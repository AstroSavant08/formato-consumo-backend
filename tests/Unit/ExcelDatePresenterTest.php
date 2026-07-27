<?php

namespace Tests\Unit;

use App\Support\ExcelDatePresenter;
use PHPUnit\Framework\TestCase;

class ExcelDatePresenterTest extends TestCase
{
    public function test_presents_excel_serial_as_day_month_year(): void
    {
        $this->assertSame('21/02/2024', ExcelDatePresenter::present('45343'));
        $this->assertSame('22/02/2024', ExcelDatePresenter::present('45344'));
        $this->assertSame('19/02/2024', ExcelDatePresenter::present('45341'));
    }

    public function test_formats_readable_iso_date_string(): void
    {
        $this->assertSame('15/03/2024', ExcelDatePresenter::present('2024-03-15'));
    }

    public function test_returns_original_value_when_not_parseable(): void
    {
        $this->assertSame('fecha-invalida', ExcelDatePresenter::present('fecha-invalida'));
    }

    public function test_returns_null_for_empty_value(): void
    {
        $this->assertNull(ExcelDatePresenter::present(null));
        $this->assertNull(ExcelDatePresenter::present(''));
    }
}
