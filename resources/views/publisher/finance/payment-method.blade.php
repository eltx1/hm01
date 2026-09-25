@extends('layouts.admin')
@section('title', 'Payment details')
@section('heading', 'Payment details')
@section('content')
@include('publisher.finance._tabs')
<x-payment-profile-editor :profile="$profile" :action="route('publisher.finance.payment-method.update')" :can-edit="auth()->user()->hasPermission('finance.publisher.payment_profile.manage')" :cancel-href="route('publisher.finance.overview')" />
@endsection
