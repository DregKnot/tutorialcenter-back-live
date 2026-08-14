<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CognitiveTest extends Model
{
    protected $table = 'cognitive_tests';

    protected $fillable = [
        'student_name',
<<<<<<< HEAD
=======
        'contact',
>>>>>>> 9c879873ce75ee779f616888dc10df030d19121f
        'school',
        'test_started_at',
        'test_ended_at',
        'score',
    ];

    protected $casts = [
        'test_started_at' => 'datetime',
        'test_ended_at' => 'datetime',
        'score' => 'integer',
    ];
}
