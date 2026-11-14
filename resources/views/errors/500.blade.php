@extends('errors::layout')

@section('code', '500')
@section('title', 'Something went wrong on our side')
@section('message', 'It is our fault, not yours. Try again in a minute. If it keeps happening, quote the reference below.')
@section('title_bn', 'আমাদের দিকে কিছু সমস্যা হয়েছে')
@section('message_bn', 'এটা আমাদের ভুল, আপনার নয়। এক মিনিট পর আবার চেষ্টা করুন। বারবার হলে নিচের রেফারেন্সটি জানান।')
@section('actions')
    <a class="button" href="{{ url()->current() }}">Try again · <span lang="bn" class="lang-bn">আবার চেষ্টা করুন</span></a>
@endsection
