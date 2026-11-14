@extends('errors::layout')

@section('code', '404')
@section('title', 'This page isn\'t here')
@section('message', 'The link may be old or mistyped. Nothing happened to any order.')
@section('title_bn', 'এই পাতাটি নেই')
@section('message_bn', 'লিংকটি পুরনো হতে পারে, বা টাইপ করতে ভুল হয়েছে। কোনো অর্ডারের কিছুই হয়নি।')
@section('actions')
    <a class="button" href="{{ url('/admin') }}">Admin dashboard · <span lang="bn" class="lang-bn">ড্যাশবোর্ড</span></a>
@endsection
