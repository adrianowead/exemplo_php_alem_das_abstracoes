// src/lib.rs
// Biblioteca Rust para demonstrar FFI com PHP

use std::ffi::CStr;
use std::os::raw::c_char;

/// Função exposta para C/PHP: calcula hash simples (djb2)
/// Este algoritmo é didático, não use em produção para segurança.
#[no_mangle]
pub extern "C" fn hash_djb2(input: *const c_char) -> u64 {
    let c_str = unsafe {
        if input.is_null() {
            return 0;
        }
        CStr::from_ptr(input)
    };
    
    let bytes = c_str.to_bytes();
    let mut hash: u64 = 5381;
    
    for &byte in bytes {
        // hash * 33 + byte
        hash = hash.wrapping_mul(33).wrapping_add(byte as u64);
    }
    
    hash
}

/// Fibonacci otimizado (iterativo)
#[no_mangle]
pub extern "C" fn fibonacci(n: u32) -> u64 {
    if n <= 1 {
        return n as u64;
    }
    
    let mut a: u64 = 0;
    let mut b: u64 = 1;
    
    for _ in 2..=n {
        let temp = a.wrapping_add(b);
        a = b;
        b = temp;
    }
    
    b
}

/// Soma de array (demonstra passagem de ponteiro + tamanho)
#[no_mangle]
pub extern "C" fn soma_array(arr: *const i64, len: usize) -> i64 {
    if arr.is_null() || len == 0 {
        return 0;
    }
    
    let slice = unsafe { std::slice::from_raw_parts(arr, len) };
    slice.iter().sum()
}