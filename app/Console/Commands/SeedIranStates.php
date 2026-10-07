<?php

namespace App\Console\Commands;

use App\Models\State;
use Illuminate\Console\Command;

class SeedIranStates extends Command
{
    protected $signature = 'geo:seed-states';

    protected $description = 'درج/به‌روزرسانی ۳۱ استان ایران برای فرم مشاوره و نمایندگی';

    /** @var list<array{id:int,name:string,code:string}> */
    private const STATES = [
        ['id' => 1, 'name' => 'اردبیل', 'code' => '1'],
        ['id' => 2, 'name' => 'اصفهان', 'code' => '2'],
        ['id' => 3, 'name' => 'البرز', 'code' => '3'],
        ['id' => 4, 'name' => 'ایلام', 'code' => '4'],
        ['id' => 5, 'name' => 'آذربایجان شرقی', 'code' => '5'],
        ['id' => 6, 'name' => 'آذربایجان غربی', 'code' => '6'],
        ['id' => 7, 'name' => 'بوشهر', 'code' => '7'],
        ['id' => 8, 'name' => 'تهران', 'code' => '8'],
        ['id' => 9, 'name' => 'چهارمحال و بختیاری', 'code' => '9'],
        ['id' => 10, 'name' => 'خراسان جنوبی', 'code' => '10'],
        ['id' => 11, 'name' => 'خراسان رضوی', 'code' => '11'],
        ['id' => 12, 'name' => 'خراسان شمالی', 'code' => '12'],
        ['id' => 13, 'name' => 'خوزستان', 'code' => '13'],
        ['id' => 14, 'name' => 'زنجان', 'code' => '14'],
        ['id' => 15, 'name' => 'سمنان', 'code' => '15'],
        ['id' => 16, 'name' => 'سیستان و بلوچستان', 'code' => '16'],
        ['id' => 17, 'name' => 'فارس', 'code' => '17'],
        ['id' => 18, 'name' => 'قزوین', 'code' => '18'],
        ['id' => 19, 'name' => 'قم', 'code' => '19'],
        ['id' => 20, 'name' => 'کردستان', 'code' => '20'],
        ['id' => 21, 'name' => 'کرمان', 'code' => '21'],
        ['id' => 22, 'name' => 'کرمانشاه', 'code' => '22'],
        ['id' => 23, 'name' => 'کهگیلویه و بویراحمد', 'code' => '23'],
        ['id' => 24, 'name' => 'گلستان', 'code' => '24'],
        ['id' => 25, 'name' => 'گیلان', 'code' => '25'],
        ['id' => 26, 'name' => 'لرستان', 'code' => '26'],
        ['id' => 27, 'name' => 'مازندران', 'code' => '27'],
        ['id' => 28, 'name' => 'مرکزی', 'code' => '28'],
        ['id' => 29, 'name' => 'هرمزگان', 'code' => '29'],
        ['id' => 30, 'name' => 'همدان', 'code' => '30'],
        ['id' => 31, 'name' => 'یزد', 'code' => '31'],
    ];

    public function handle(): int
    {
        foreach (self::STATES as $row) {
            State::query()->updateOrCreate(
                ['id' => $row['id']],
                ['name' => $row['name'], 'code' => $row['code']],
            );
        }

        $count = State::query()->count();
        $this->info("استان‌ها آماده شد. تعداد: {$count}");

        return self::SUCCESS;
    }
}
