<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $ciphertext
 * @property string $key_id
 */
class MailAccountCredential extends Model
{
    protected $guarded = [];

    protected $hidden = ['ciphertext'];
}
