<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines contain the default error messages used by
    | the validator class. Some of these rules have multiple versions such
    | as the size rules. Feel free to tweak each of these messages here.
    |
    */

    'accepted' => ':attribute ต้องได้รับการยอมรับ',
    'accepted_if' => ':attribute ต้องได้รับการยอมรับเมื่อ :other เป็น :value',
    'active_url' => ':attribute ไม่ใช่ URL ที่ถูกต้อง',
    'after' => ':attribute ต้องเป็นวันที่หลังจาก :date',
    'after_or_equal' => ':attribute ต้องเป็นวันที่หลังจากหรือเท่ากับ :date',
    'alpha' => ':attribute ต้องมีเฉพาะตัวอักษรเท่านั้น',
    'alpha_dash' => ':attribute ต้องมีเฉพาะตัวอักษร ตัวเลข เครื่องหมายขีดกลาง และขีดเส้นใต้เท่านั้น',
    'alpha_num' => ':attribute ต้องมีเฉพาะตัวอักษรและตัวเลขเท่านั้น',
    'array' => ':attribute ต้องเป็นอาเรย์',
    'before' => ':attribute ต้องเป็นวันที่ก่อน :date',
    'before_or_equal' => ':attribute ต้องเป็นวันที่ก่อนหรือเท่ากับ :date',
    'between' => [
        'array' => ':attribute ต้องมีระหว่าง :min ถึง :max รายการ',
        'file' => ':attribute ต้องมีขนาดระหว่าง :min ถึง :max กิโลไบต์',
        'numeric' => ':attribute ต้องอยู่ระหว่าง :min ถึง :max',
        'string' => ':attribute ต้องมีความยาวระหว่าง :min ถึง :max ตัวอักษร',
    ],
    'boolean' => 'ฟิลด์ :attribute ต้องเป็น true หรือ false',
    'confirmed' => 'การยืนยัน :attribute ไม่ตรงกัน',
    'current_password' => 'รหัสผ่านไม่ถูกต้อง',
    'date' => ':attribute ไม่ใช่วันที่ที่ถูกต้อง',
    'date_equals' => ':attribute ต้องเป็นวันที่เท่ากับ :date',
    'date_format' => ':attribute ไม่ตรงกับรูปแบบ :format',
    'declined' => ':attribute ต้องถูกปฏิเสธ',
    'declined_if' => ':attribute ต้องถูกปฏิเสธเมื่อ :other เป็น :value',
    'different' => ':attribute และ :other ต้องแตกต่างกัน',
    'digits' => ':attribute ต้องเป็น :digits หลัก',
    'digits_between' => ':attribute ต้องมีระหว่าง :min ถึง :max หลัก',
    'dimensions' => ':attribute มีขนาดภาพที่ไม่ถูกต้อง',
    'distinct' => 'ฟิลด์ :attribute มีค่าซ้ำ',
    'doesnt_end_with' => ':attribute ต้องไม่ลงท้ายด้วยค่าใดค่าหนึ่งต่อไปนี้: :values',
    'doesnt_start_with' => ':attribute ต้องไม่เริ่มต้นด้วยค่าใดค่าหนึ่งต่อไปนี้: :values',
    'email' => ':attribute ต้องเป็นที่อยู่อีเมลที่ถูกต้อง',
    'ends_with' => ':attribute ต้องลงท้ายด้วยค่าใดค่าหนึ่งต่อไปนี้: :values',
    'enum' => ':attribute ที่เลือกไม่ถูกต้อง',
    'exists' => ':attribute ที่เลือกไม่ถูกต้อง',
    'file' => ':attribute ต้องเป็นไฟล์',
    'filled' => 'ฟิลด์ :attribute ต้องมีค่า',
    'gt' => [
        'array' => ':attribute ต้องมีมากกว่า :value รายการ',
        'file' => ':attribute ต้องมีขนาดมากกว่า :value กิโลไบต์',
        'numeric' => ':attribute ต้องมากกว่า :value',
        'string' => ':attribute ต้องมีความยาวมากกว่า :value ตัวอักษร',
    ],
    'gte' => [
        'array' => ':attribute ต้องมี :value รายการหรือมากกว่า',
        'file' => ':attribute ต้องมีขนาดมากกว่าหรือเท่ากับ :value กิโลไบต์',
        'numeric' => ':attribute ต้องมากกว่าหรือเท่ากับ :value',
        'string' => ':attribute ต้องมีความยาวมากกว่าหรือเท่ากับ :value ตัวอักษร',
    ],
    'image' => ':attribute ต้องเป็นภาพ',
    'in' => ':attribute ที่เลือกไม่ถูกต้อง',
    'in_array' => 'ฟิลด์ :attribute ไม่มีอยู่ใน :other',
    'integer' => ':attribute ต้องเป็นจำนวนเต็ม',
    'ip' => ':attribute ต้องเป็นที่อยู่ IP ที่ถูกต้อง',
    'ipv4' => ':attribute ต้องเป็นที่อยู่ IPv4 ที่ถูกต้อง',
    'ipv6' => ':attribute ต้องเป็นที่อยู่ IPv6 ที่ถูกต้อง',
    'json' => ':attribute ต้องเป็นสตริง JSON ที่ถูกต้อง',
    'lt' => [
        'array' => ':attribute ต้องมีน้อยกว่า :value รายการ',
        'file' => ':attribute ต้องมีขนาดน้อยกว่า :value กิโลไบต์',
        'numeric' => ':attribute ต้องน้อยกว่า :value',
        'string' => ':attribute ต้องมีความยาวน้อยกว่า :value ตัวอักษร',
    ],
    'lte' => [
        'array' => ':attribute ต้องไม่มีมากกว่า :value รายการ',
        'file' => ':attribute ต้องมีขนาดน้อยกว่าหรือเท่ากับ :value กิโลไบต์',
        'numeric' => ':attribute ต้องน้อยกว่าหรือเท่ากับ :value',
        'string' => ':attribute ต้องมีความยาวน้อยกว่าหรือเท่ากับ :value ตัวอักษร',
    ],
    'mac_address' => ':attribute ต้องเป็นที่อยู่ MAC ที่ถูกต้อง',
    'max' => [
        'array' => ':attribute ต้องไม่มีมากกว่า :max รายการ',
        'file' => ':attribute ต้องมีขนาดไม่เกิน :max กิโลไบต์',
        'numeric' => ':attribute ต้องไม่เกิน :max',
        'string' => ':attribute ต้องมีความยาวไม่เกิน :max ตัวอักษร',
    ],
    'max_digits' => ':attribute ต้องมีไม่เกิน :max หลัก',
    'mimes' => ':attribute ต้องเป็นไฟล์ประเภท: :values',
    'mimetypes' => ':attribute ต้องเป็นไฟล์ประเภท: :values',
    'min' => [
        'array' => ':attribute ต้องมีอย่างน้อย :min รายการ',
        'file' => ':attribute ต้องมีขนาดอย่างน้อย :min กิโลไบต์',
        'numeric' => ':attribute ต้องมีอย่างน้อย :min',
        'string' => ':attribute ต้องมีความยาวอย่างน้อย :min ตัวอักษร',
    ],
    'min_digits' => ':attribute ต้องมีอย่างน้อย :min หลัก',
    'multiple_of' => ':attribute ต้องเป็นทวีคูณของ :value',
    'not_in' => ':attribute ที่เลือกไม่ถูกต้อง',
    'not_regex' => 'รูปแบบ :attribute ไม่ถูกต้อง',
    'numeric' => ':attribute ต้องเป็นตัวเลข',
    'password' => [
        'letters' => ':attribute ต้องมีตัวอักษรอย่างน้อยหนึ่งตัว',
        'mixed' => ':attribute ต้องมีตัวอักษรตัวใหญ่และตัวเล็กอย่างน้อยหนึ่งตัว',
        'numbers' => ':attribute ต้องมีตัวเลขอย่างน้อยหนึ่งตัว',
        'symbols' => ':attribute ต้องมีสัญลักษณ์อย่างน้อยหนึ่งตัว',
        'uncompromised' => ':attribute ที่ให้มาปรากฏในการรั่วไหลของข้อมูล กรุณาเลือก :attribute อื่น',
    ],
    'present' => 'ฟิลด์ :attribute ต้องมีอยู่',
    'prohibited' => 'ฟิลด์ :attribute ถูกห้าม',
    'prohibited_if' => 'ฟิลด์ :attribute ถูกห้ามเมื่อ :other เป็น :value',
    'prohibited_unless' => 'ฟิลด์ :attribute ถูกห้ามเว้นแต่ :other อยู่ใน :values',
    'prohibits' => 'ฟิลด์ :attribute ห้าม :other จากการมีอยู่',
    'regex' => 'รูปแบบ :attribute ไม่ถูกต้อง',
    'required' => 'ฟิลด์ :attribute จำเป็นต้องมี',
    'required_array_keys' => 'ฟิลด์ :attribute ต้องมีรายการสำหรับ: :values',
    'required_if' => 'ฟิลด์ :attribute จำเป็นต้องมีเมื่อ :other เป็น :value',
    'required_if_accepted' => 'ฟิลด์ :attribute จำเป็นต้องมีเมื่อ :other ได้รับการยอมรับ',
    'required_unless' => 'ฟิลด์ :attribute จำเป็นต้องมีเว้นแต่ :other อยู่ใน :values',
    'required_with' => 'ฟิลด์ :attribute จำเป็นต้องมีเมื่อ :values มีอยู่',
    'required_with_all' => 'ฟิลด์ :attribute จำเป็นต้องมีเมื่อ :values มีอยู่',
    'required_without' => 'ฟิลด์ :attribute จำเป็นต้องมีเมื่อ :values ไม่มีอยู่',
    'required_without_all' => 'ฟิลด์ :attribute จำเป็นต้องมีเมื่อไม่มี :values ใดๆ อยู่',
    'same' => ':attribute และ :other ต้องตรงกัน',
    'size' => [
        'array' => ':attribute ต้องมี :size รายการ',
        'file' => ':attribute ต้องมีขนาด :size กิโลไบต์',
        'numeric' => ':attribute ต้องเป็น :size',
        'string' => ':attribute ต้องมีความยาว :size ตัวอักษร',
    ],
    'starts_with' => ':attribute ต้องเริ่มต้นด้วยค่าใดค่าหนึ่งต่อไปนี้: :values',
    'string' => ':attribute ต้องเป็นสตริง',
    'timezone' => ':attribute ต้องเป็นเขตเวลาที่ถูกต้อง',
    'unique' => ':attribute ถูกใช้แล้ว',
    'uploaded' => ':attribute อัปโหลดไม่สำเร็จ',
    'url' => ':attribute ต้องเป็น URL ที่ถูกต้อง',
    'uuid' => ':attribute ต้องเป็น UUID ที่ถูกต้อง',

    // Custom validation messages for Telegram module
    'url_resource_required' => 'ต้องระบุ url หรือ resource_id อย่างน้อยหนึ่งรายการ',
    'url_format_invalid' => 'รูปแบบ url ไม่ถูกต้อง',
    'url_resource_duplicate' => 'url หรือ resource_id นี้มีอยู่แล้ว',

    // Custom system messages
    'route_not_found_or_register' => 'ไม่พบเส้นทางหรือไม่ได้ลงทะเบียน',
    'Server Error' => 'ข้อผิดพลาดเซิร์ฟเวอร์',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Here you may specify custom validation messages for attributes using the
    | convention "attribute.rule" to name the lines. This makes it quick to
    | specify a specific custom language line for a given attribute rule.
    |
    */

    'custom' => [
        'attribute-name' => [
            'rule-name' => 'custom-message',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | The following language lines are used to swap our attribute placeholder
    | with something more reader friendly such as "E-Mail Address" instead
    | of "email". This simply helps us make our message more expressive.
    |
    */

    'attributes' => [],

];
