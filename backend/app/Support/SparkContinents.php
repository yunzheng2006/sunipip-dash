<?php

namespace App\Support;

/**
 * Spark area_country.continent_id 的静态映射。
 * area_continent 表在 spark_area.sql 里并未导入，这里用 code + 中文名直接维护。
 *
 * continent_id 的映射来自 area_country 数据本身的分布。
 */
class SparkContinents
{
    public const CONTINENTS = [
        1 => ['code' => 'AS', 'name' => '亚洲', 'en' => 'Asia'],
        2 => ['code' => 'EU', 'name' => '欧洲', 'en' => 'Europe'],
        3 => ['code' => 'AF', 'name' => '非洲', 'en' => 'Africa'],
        4 => ['code' => 'OC', 'name' => '大洋洲', 'en' => 'Oceania'],
        5 => ['code' => 'SA', 'name' => '南美洲', 'en' => 'South America'],
        6 => ['code' => 'NA', 'name' => '北美洲', 'en' => 'North America'],
        7 => ['code' => 'CA', 'name' => '南美洲', 'en' => 'Central America & Caribbean'],
    ];

    /**
     * "主流国家" 快捷分组 — 给前端一键勾选用
     */
    public const PRESETS = [
        'popular' => [
            'label' => '主流国家',
            'codes' => ['USA', 'GBR', 'DEU', 'FRA', 'JPN', 'KOR', 'SGP', 'CAN', 'AUS', 'HKG', 'TWN'],
        ],
        'north_america' => [
            'label' => '北美',
            'codes' => ['USA', 'CAN', 'MEX'],
        ],
        'europe_major' => [
            'label' => '欧洲主要',
            'codes' => ['GBR', 'DEU', 'FRA', 'ITA', 'ESP', 'NLD', 'CHE', 'SWE', 'POL', 'RUS'],
        ],
        'asia_major' => [
            'label' => '亚洲主要',
            'codes' => ['JPN', 'KOR', 'SGP', 'HKG', 'TWN', 'THA', 'MYS', 'IDN', 'VNM', 'PHL', 'IND'],
        ],
        'southeast_asia' => [
            'label' => '东南亚',
            'codes' => ['SGP', 'MYS', 'IDN', 'THA', 'VNM', 'PHL', 'KHM', 'LAO', 'MMR'],
        ],
        'middle_east' => [
            'label' => '中东',
            'codes' => ['ARE', 'SAU', 'TUR', 'ISR', 'QAT', 'KWT'],
        ],
        'oceania' => [
            'label' => '大洋洲',
            'codes' => ['AUS', 'NZL'],
        ],
        'south_america' => [
            'label' => '南美',
            'codes' => ['BRA', 'ARG', 'CHL', 'COL', 'PER', 'MEX'],
        ],
    ];
}
