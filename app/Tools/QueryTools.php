<?php


namespace App\Tools;


use Morilog\Jalali\Jalalian;

trait QueryTools
{
    public function scopeSearch($query, $searchTerms)
    {
        if ($searchTerms) {
            foreach ($searchTerms->restrictionList as $index => $searchTerm) {
                switch ($searchTerm->fieldOperation) {
                    case "MATCH":
                        if (str_contains($searchTerm->fieldName, ".")) {
                            $names = explode(".", $searchTerm->fieldName);
                            $lastIndex = sizeof($names) - 1;
                            $column = $names[$lastIndex];
                            array_pop($names);
                            $names = implode(".", $names);
                            $query->whereHas($names, function ($q) use ($searchTerm, $column) {
                                $q->where($column, "like", "%" . $searchTerm->fieldValue . "%");
                            });
                        } else {
                            $query->where($searchTerm->fieldName, "like", "%" . $searchTerm->fieldValue . "%");
                        }
                        break;
                }
            }
        }
    }

    public function getCreatedAtAttribute($value): string
    {
        return Jalalian::fromDateTime($value);
    }

    public function getUpdatedAtAttribute($value): string
    {
        return Jalalian::fromDateTime($value);
    }
}
