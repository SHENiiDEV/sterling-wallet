<?php

namespace App\Http\Requests\Admin;

final class DocumentFileRules
{
    public const MAX_KB = 20480;

    public const MIMES = 'pdf,doc,docx,xls,xlsx,csv,txt,png,jpg,jpeg,zip';
}
