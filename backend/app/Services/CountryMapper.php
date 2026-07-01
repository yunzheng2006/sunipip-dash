<?php

namespace App\Services;

class CountryMapper
{
    private static array $map = [
        // 北美洲
        'ATG' => ['安提瓜和巴布达', 'AG'], 'BHS' => ['巴哈马', 'BS'], 'BMU' => ['百慕大', 'BM'],
        'BRB' => ['巴巴多斯', 'BB'], 'CAN' => ['加拿大', 'CA'], 'CRI' => ['哥斯达黎加', 'CR'],
        'CUB' => ['古巴', 'CU'], 'CYM' => ['开曼群岛', 'KY'], 'DMA' => ['多米尼克', 'DM'],
        'DOM' => ['多米尼加', 'DO'], 'GRD' => ['格林纳达', 'GD'], 'GTM' => ['危地马拉', 'GT'],
        'HND' => ['洪都拉斯', 'HN'], 'HTI' => ['海地', 'HT'], 'JAM' => ['牙买加', 'JM'],
        'LCA' => ['圣卢西亚', 'LC'], 'MEX' => ['墨西哥', 'MX'], 'NIC' => ['尼加拉瓜', 'NI'],
        'PAN' => ['巴拿马', 'PA'], 'PRI' => ['波多黎各', 'PR'], 'SLV' => ['萨尔瓦多', 'SV'],
        'TTO' => ['特立尼达和多巴哥', 'TT'], 'USA' => ['美国', 'US'], 'VCT' => ['圣文森特', 'VC'],
        // 亚洲
        'AFG' => ['阿富汗', 'AF'], 'AZE' => ['阿塞拜疆', 'AZ'], 'BGD' => ['孟加拉', 'BD'],
        'BRN' => ['文莱', 'BN'], 'BTN' => ['不丹', 'BT'], 'CHN' => ['中国', 'CN'],
        'CYP' => ['塞浦路斯', 'CY'], 'GEO' => ['格鲁吉亚', 'GE'], 'HKG' => ['香港', 'HK'],
        'IDN' => ['印尼', 'ID'], 'IND' => ['印度', 'IN'], 'JPN' => ['日本', 'JP'],
        'KAZ' => ['哈萨克斯坦', 'KZ'], 'KGZ' => ['吉尔吉斯斯坦', 'KG'], 'KHM' => ['柬埔寨', 'KH'],
        'KOR' => ['韩国', 'KR'], 'LAO' => ['老挝', 'LA'], 'LKA' => ['斯里兰卡', 'LK'],
        'MDV' => ['马尔代夫', 'MV'], 'MMR' => ['缅甸', 'MM'], 'MNG' => ['蒙古', 'MN'],
        'MYS' => ['马来西亚', 'MY'], 'NPL' => ['尼泊尔', 'NP'], 'PAK' => ['巴基斯坦', 'PK'],
        'PHL' => ['菲律宾', 'PH'], 'SGP' => ['新加坡', 'SG'], 'THA' => ['泰国', 'TH'],
        'TJK' => ['塔吉克斯坦', 'TJ'], 'TKM' => ['土库曼斯坦', 'TM'], 'TLS' => ['东帝汶', 'TL'],
        'TWN' => ['台湾', 'TW'], 'UZB' => ['乌兹别克斯坦', 'UZ'], 'VNM' => ['越南', 'VN'],
        // 中东
        'ARE' => ['阿联酋', 'AE'], 'BHR' => ['巴林', 'BH'], 'IRN' => ['伊朗', 'IR'],
        'IRQ' => ['伊拉克', 'IQ'], 'ISR' => ['以色列', 'IL'], 'JOR' => ['约旦', 'JO'],
        'KWT' => ['科威特', 'KW'], 'LBN' => ['黎巴嫩', 'LB'], 'OMN' => ['阿曼', 'OM'],
        'PSE' => ['巴勒斯坦', 'PS'], 'QAT' => ['卡塔尔', 'QA'], 'SAU' => ['沙特阿拉伯', 'SA'],
        'SYR' => ['叙利亚', 'SY'], 'TUR' => ['土耳其', 'TR'], 'YEM' => ['也门', 'YE'],
        // 欧洲
        'ALB' => ['阿尔巴尼亚', 'AL'], 'ARM' => ['亚美尼亚', 'AM'], 'AUT' => ['奥地利', 'AT'],
        'BEL' => ['比利时', 'BE'], 'BGR' => ['保加利亚', 'BG'], 'BIH' => ['波黑', 'BA'],
        'BLR' => ['白俄罗斯', 'BY'], 'CHE' => ['瑞士', 'CH'], 'CZE' => ['捷克', 'CZ'],
        'DEU' => ['德国', 'DE'], 'DNK' => ['丹麦', 'DK'], 'ESP' => ['西班牙', 'ES'],
        'EST' => ['爱沙尼亚', 'EE'], 'FIN' => ['芬兰', 'FI'], 'FRA' => ['法国', 'FR'],
        'GBR' => ['英国', 'GB'], 'GRC' => ['希腊', 'GR'], 'HRV' => ['克罗地亚', 'HR'],
        'HUN' => ['匈牙利', 'HU'], 'IRL' => ['爱尔兰', 'IE'], 'ISL' => ['冰岛', 'IS'],
        'ITA' => ['意大利', 'IT'], 'LTU' => ['立陶宛', 'LT'], 'LUX' => ['卢森堡', 'LU'],
        'LVA' => ['拉脱维亚', 'LV'], 'MDA' => ['摩尔多瓦', 'MD'], 'MKD' => ['北马其顿', 'MK'],
        'MLT' => ['马耳他', 'MT'], 'MNE' => ['黑山', 'ME'], 'NLD' => ['荷兰', 'NL'],
        'NOR' => ['挪威', 'NO'], 'POL' => ['波兰', 'PL'], 'PRT' => ['葡萄牙', 'PT'],
        'ROU' => ['罗马尼亚', 'RO'], 'RUS' => ['俄罗斯', 'RU'], 'SRB' => ['塞尔维亚', 'RS'],
        'SVK' => ['斯洛伐克', 'SK'], 'SVN' => ['斯洛文尼亚', 'SI'], 'SWE' => ['瑞典', 'SE'],
        'UKR' => ['乌克兰', 'UA'],
        // 南美洲
        'ARG' => ['阿根廷', 'AR'], 'BOL' => ['玻利维亚', 'BO'], 'BRA' => ['巴西', 'BR'],
        'CHL' => ['智利', 'CL'], 'COL' => ['哥伦比亚', 'CO'], 'ECU' => ['厄瓜多尔', 'EC'],
        'GUY' => ['圭亚那', 'GY'], 'PER' => ['秘鲁', 'PE'], 'PRY' => ['巴拉圭', 'PY'],
        'SUR' => ['苏里南', 'SR'], 'URY' => ['乌拉圭', 'UY'], 'VEN' => ['委内瑞拉', 'VE'],
        // 大洋洲
        'AUS' => ['澳大利亚', 'AU'], 'FJI' => ['斐济', 'FJ'], 'NZL' => ['新西兰', 'NZ'],
        'PNG' => ['巴布亚新几内亚', 'PG'], 'TON' => ['汤加', 'TO'], 'VUT' => ['瓦努阿图', 'VU'],
        // 非洲
        'AGO' => ['安哥拉', 'AO'], 'BDI' => ['布隆迪', 'BI'], 'BEN' => ['贝宁', 'BJ'],
        'BFA' => ['布基纳法索', 'BF'], 'BWA' => ['博茨瓦纳', 'BW'], 'CAF' => ['中非', 'CF'],
        'CIV' => ['科特迪瓦', 'CI'], 'CMR' => ['喀麦隆', 'CM'], 'COD' => ['刚果(金)', 'CD'],
        'COG' => ['刚果(布)', 'CG'], 'COM' => ['科摩罗', 'KM'], 'CPV' => ['佛得角', 'CV'],
        'DJI' => ['吉布提', 'DJ'], 'DZA' => ['阿尔及利亚', 'DZ'], 'EGY' => ['埃及', 'EG'],
        'ERI' => ['厄立特里亚', 'ER'], 'ETH' => ['埃塞俄比亚', 'ET'], 'GAB' => ['加蓬', 'GA'],
        'GHA' => ['加纳', 'GH'], 'GIN' => ['几内亚', 'GN'], 'GMB' => ['冈比亚', 'GM'],
        'GNQ' => ['赤道几内亚', 'GQ'], 'KEN' => ['肯尼亚', 'KE'], 'LBR' => ['利比里亚', 'LR'],
        'LBY' => ['利比亚', 'LY'], 'LSO' => ['莱索托', 'LS'], 'MAR' => ['摩洛哥', 'MA'],
        'MDG' => ['马达加斯加', 'MG'], 'MLI' => ['马里', 'ML'], 'MOZ' => ['莫桑比克', 'MZ'],
        'MRT' => ['毛里塔尼亚', 'MR'], 'MUS' => ['毛里求斯', 'MU'], 'MWI' => ['马拉维', 'MW'],
        'NAM' => ['纳米比亚', 'NA'], 'NER' => ['尼日尔', 'NE'], 'NGA' => ['尼日利亚', 'NG'],
        'RWA' => ['卢旺达', 'RW'], 'SDN' => ['苏丹', 'SD'], 'SEN' => ['塞内加尔', 'SN'],
        'SLE' => ['塞拉利昂', 'SL'], 'SOM' => ['索马里', 'SO'], 'SSD' => ['南苏丹', 'SS'],
        'SWZ' => ['斯威士兰', 'SZ'], 'TCD' => ['乍得', 'TD'], 'TGO' => ['多哥', 'TG'],
        'TUN' => ['突尼斯', 'TN'], 'TZA' => ['坦桑尼亚', 'TZ'], 'UGA' => ['乌干达', 'UG'],
        'ZAF' => ['南非', 'ZA'], 'ZMB' => ['赞比亚', 'ZM'], 'ZWE' => ['津巴布韦', 'ZW'],
    ];

    public static function toCn(?string $code): ?string
    {
        if (!$code) return null;
        $clean = strtoupper(substr($code, 0, 3));
        return self::$map[$clean][0] ?? null;
    }

    public static function toIso2(?string $code): ?string
    {
        if (!$code) return null;
        $clean = strtoupper(substr($code, 0, 3));
        return self::$map[$clean][1] ?? null;
    }

    public static function resolve(?string $code): array
    {
        if (!$code) return ['cn' => null, 'iso2' => null, 'iso3' => null];
        $clean = strtoupper(substr($code, 0, 3));
        $entry = self::$map[$clean] ?? null;
        return [
            'cn'   => $entry[0] ?? $clean,
            'iso2' => $entry[1] ?? '',
            'iso3' => $clean,
        ];
    }
}
